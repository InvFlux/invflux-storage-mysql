<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Order;

use Nandan108\InvFlux\Domain\Order\MonetaryEventPayload;
use Nandan108\InvFlux\Domain\Order\OrderEvent;
use Nandan108\InvFlux\Domain\Order\OrderEventStore;
use Nandan108\InvFlux\Domain\Order\OrderEventType;
use Nandan108\InvFlux\Domain\Order\RefundMode;

/**
 * MySQL-backed implementation of {@see OrderEventStore}.
 *
 * Write-mostly by design. There is intentionally no `update()` / `delete()` — the
 * core interface omits them, the underlying table is treated as append-only, and
 * reversals are appended as new events with the same `correlation_id`.
 *
 * @api
 */
final class MysqlOrderEventStore implements OrderEventStore
{
    #[\Override]
    public function append(OrderEvent $event): OrderEvent
    {
        $event->save();

        return $event;
    }

    #[\Override]
    public function forOrder(string $orderId, int $limit = 200): array
    {
        $set = OrderEvent::find(
            '`order_id` = ?',
            [$orderId],
            sprintf('ORDER BY `occurred_at` DESC, `id` DESC LIMIT %d', max(1, $limit)),
        );
        $list = [];
        foreach ($set as $event) {
            $list[] = $event;
        }

        return $list;
    }

    #[\Override]
    public function byCorrelation(string $correlationId): array
    {
        $set = OrderEvent::find(
            '`correlation_id` = ?',
            [$correlationId],
            'ORDER BY `occurred_at` ASC, `id` ASC',
        );
        $list = [];
        foreach ($set as $event) {
            $list[] = $event;
        }

        return $list;
    }

    #[\Override]
    public function findDueScheduledRefunds(\DateTimeImmutable $now, int $limit): array
    {
        // Column-level predicate (`event_type`, `occurred_at`) is cheap + indexed
        // (idx_event_time). `mode` lives in the JSON payload and the terminal-sibling
        // check spans correlated rows, so both are applied in PHP — refund-schedule
        // volumes per tick are tiny. The generous fetch cap bounds the scan; a
        // pathological backlog of unsettled manual schedules can't starve autos at
        // SMB scale (they'd have to number in the hundreds simultaneously).
        $set = OrderEvent::find(
            '`event_type` = ? AND `occurred_at` <= ?',
            [OrderEventType::RefundScheduled->value, $now->format('Y-m-d H:i:s.u')],
            'ORDER BY `occurred_at` ASC, `id` ASC LIMIT 500',
        );

        $due = [];
        foreach ($set as $scheduled) {
            $payload = \is_array($scheduled->payload) ? $scheduled->payload : [];
            if (RefundMode::Manual->value === ($payload['mode'] ?? null)) {
                continue; // pending-manual schedules are settled by the operator, never auto-executed
            }
            if ($this->hasTerminalSibling($scheduled)) {
                continue; // already confirmed or cancelled
            }
            $due[] = $scheduled;
            if (\count($due) >= $limit) {
                break;
            }
        }

        return $due;
    }

    /**
     * Whether a `refund.scheduled` has a terminal sibling — a
     * `correction.refund_confirmed` or `refund.cancelled` sharing its correlation_id
     * and back-referencing it via payload `scheduled_event_id`. `refund.failed` is
     * non-terminal (retryable), so it does not count.
     */
    private function hasTerminalSibling(OrderEvent $scheduled): bool
    {
        $correlationId = $scheduled->correlation_id;
        if (null === $correlationId || '' === $correlationId) {
            return false;
        }
        $scheduledHex = \bin2hex((string) $scheduled->id);
        foreach ($this->byCorrelation($correlationId) as $sibling) {
            if (OrderEventType::CorrectionRefundConfirmed->value !== $sibling->event_type
                && OrderEventType::RefundCancelled->value !== $sibling->event_type) {
                continue;
            }
            // correction.refund_confirmed → array payload; refund.cancelled → monetary VO.
            /** @psalm-var mixed $sid */
            $sid = $sibling->payload instanceof MonetaryEventPayload
                ? ($sibling->payload->extras['scheduled_event_id'] ?? null)
                : (\is_array($sibling->payload) ? ($sibling->payload['scheduled_event_id'] ?? null) : null);
            if ($sid === $scheduledHex) {
                return true;
            }
        }

        return false;
    }
}

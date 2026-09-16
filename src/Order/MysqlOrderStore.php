<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Order;

use Nandan108\Attrecord\Record;
use Nandan108\Attrecord\WhereClause;
use Nandan108\InvFlux\Domain\Annotation\Annotation;
use Nandan108\InvFlux\Domain\Order\BuiltInCorrectionReasons;
use Nandan108\InvFlux\Domain\Order\BuiltInCorrectionTypes;
use Nandan108\InvFlux\Domain\Order\CorrectionTiming;
use Nandan108\InvFlux\Domain\Order\Order;
use Nandan108\InvFlux\Domain\Order\OrderCharge;
use Nandan108\InvFlux\Domain\Order\OrderCorrection;
use Nandan108\InvFlux\Domain\Order\OrderCorrectionReason;
use Nandan108\InvFlux\Domain\Order\OrderCorrectionType;
use Nandan108\InvFlux\Domain\Order\OrderEvent;
use Nandan108\InvFlux\Domain\Order\OrderLine;
use Nandan108\InvFlux\Domain\Order\OrderPayment;
use Nandan108\InvFlux\Domain\Order\OrderShipping;
use Nandan108\InvFlux\Domain\Order\TaxLine;
use Nandan108\InvFlux\Domain\Shipment\Shipment;
use Nandan108\InvFlux\Domain\Shipment\ShipmentLine;
use Nandan108\InvFlux\Domain\Tag\Tag;
use Nandan108\InvFlux\Domain\Tag\TagAssignment;

/**
 * Declaration + seeding for the Order domain.
 *
 * Names the order-domain Record set ({@see ORDER_RECORDS}) for the schema installer, and seeds
 * the two static registries (`invflux_order_correction_types`,
 * `invflux_order_correction_reasons`) from the canonical core seeds at
 * {@see BuiltInCorrectionTypes::all()} and {@see BuiltInCorrectionReasons::all()}.
 *
 * The actual CRUD lives in the six repository implementations next to this Store
 * (`MysqlOrder*Repository`); this Store is schema-declaration/bootstrap only.
 *
 * @api
 */
final class MysqlOrderStore
{
    /**
     * Takes no collaborators any more: seeding goes through attrecord's ambient connection,
     * configured once at adapter wire-up. The Connection, session and table prefix existed purely
     * to emit DDL, which the schema installer owns now.
     *
     * They are still *accepted* and ignored so host adapters can drop them at their own pace —
     * the PrestaShop adapter still passes all three. New call sites should pass nothing.
     *
     * @param mixed  $connection  ignored
     * @param mixed  $session     ignored
     * @param string $tablePrefix ignored
     *
     * @psalm-suppress UnusedParam the three parameters are deliberately inert — see above. They
     *                             exist to keep existing call sites compiling, so being unreferenced
     *                             is the point, not an oversight.
     */
    public function __construct(mixed $connection = null, mixed $session = null, string $tablePrefix = '')
    {
    }

    /**
     * Seed the order-domain registries. Idempotent.
     *
     * The tables themselves are created by the schema installer from {@see ORDER_RECORDS};
     * what is left here is the built-in correction types and reasons.
     */
    public function install(): void
    {
        $this->seedRegistries();
    }

    /**
     * The order domain: orders and their lines, the event log, corrections and their
     * registries, tax lines, shipments, and the shared tag/annotation spine.
     *
     * A **set**, not a sequence — the schema installer derives creation order from the
     * declared foreign keys, including the ones pointing outside this list (OrderEvent
     * references the identity registries, which the installer sees as part of the same
     * managed model set).
     *
     * @var list<class-string<Record>>
     */
    public const ORDER_RECORDS = [
        Order::class,
        OrderLine::class,
        // The order's shipping arrangements — a sibling collection of OrderLine, not a column on
        // it, because a source system may divide one order into several packages. FKs into
        // Order.id, so it installs after it.
        OrderShipping::class,
        // The order's order-level charges (shipping's amount, fees) — a sibling of the two above,
        // FK into Order.id.
        OrderCharge::class,
        // Payments received against the order — FK into Order.id, and into Shipment.id for a
        // payment collected against one parcel.
        OrderPayment::class,
        OrderCorrectionType::class,
        OrderCorrectionReason::class,
        // OrderEvent comes before OrderCorrection because OrderCorrection.parent_event_id
        // FKs into OrderEvent.id.
        OrderEvent::class,
        OrderCorrection::class,
        // TaxLine FKs into OrderCorrection.id (and will FK into other parents in the
        // future via additional nullable FK columns); install last so its FKs resolve.
        TaxLine::class,
        // Shipment FKs into Order.id; ShipmentLine FKs into Shipment.id + OrderLine.id —
        // both targets already installed above, so the pair comes after them.
        Shipment::class,
        ShipmentLine::class,
        // Tag is the shared (cross-entity) tag taxonomy — orders are its first consumer, so it
        // installs here. Assignments are the polymorphic TagAssignment spine below.
        Tag::class,
        // Cross-entity annotation + polymorphic tag spine.
        // Orders are the first consumer, so they install here (like Tag). Annotation has no
        // FK (polymorphic target); TagAssignment FKs into Tag.id, so it follows Tag.
        Annotation::class,
        TagAssignment::class,
    ];

    /**
     * Seed the two static registries from their canonical core lists.
     *
     * Gated on `countWhere('1 = 1') === 0`: MySQL's `INSERT … ON DUPLICATE KEY
     * UPDATE` burns an AUTO_INCREMENT value on every call, even when no row is
     * inserted, which would overflow TINYINT PKs after a few dozen boots.
     *
     * Trade-off: a manually deleted row won't be restored on next boot — these
     * are install-time invariants, not runtime-managed data.
     */
    private function seedRegistries(): void
    {
        // Seed only the MISSING built-ins, matched by their unique `code`. A plain INSERT
        // runs once per actually-missing row, so no AUTO_INCREMENT is burned when the
        // registry is already full (the prior all-or-nothing gate's concern) — while a
        // newly-added built-in type/reason still lands on an existing install's next pass.
        $existingTypeCodes = [];
        foreach (OrderCorrectionType::find('1 = 1') as $row) {
            $existingTypeCodes[$row->code] = true;
        }
        foreach (BuiltInCorrectionTypes::all() as $type) {
            if (!isset($existingTypeCodes[$type->code])) {
                $type->save();
            }
        }

        /** @var array<string, OrderCorrectionReason> $existingReasons */
        $existingReasons = [];
        foreach (OrderCorrectionReason::find('1 = 1') as $row) {
            $existingReasons[$row->code] = $row;
        }
        $builtInReasons = BuiltInCorrectionReasons::all();
        foreach ($builtInReasons as $reason) {
            $existing = $existingReasons[$reason->code] ?? null;
            if (null === $existing) {
                $reason->save();
            } elseif ($existing->name !== $reason->name) {
                // A built-in reason's name is core's too. Renames are rare, so only the rows whose
                // name differs are written, each on its own.
                $existing->name = $reason->name;
                $existing->save();
            }
        }

        // A built-in reason's timing is core's to state, so a row seeded before the reason carried
        // one is brought in line — one UPDATE per timing value, not one per reason.
        /** @var array<string, list<string>> $codesByTiming */
        $codesByTiming = [];
        foreach ($builtInReasons as $reason) {
            $codesByTiming[$reason->timing->value][] = $reason->code;
        }
        foreach ($codesByTiming as $timing => $codes) {
            // The enum case, not its value: the column's caster serialises the case.
            OrderCorrectionReason::updateWhere(['timing' => CorrectionTiming::from($timing)], WhereClause::whereIn('code', $codes));
        }

        $this->removeRetiredReasons();
    }

    /**
     * Remove the retired built-in reasons nothing records. One a correction still records stays, so
     * that correction keeps its reason; the reason is no longer offered either way.
     */
    private function removeRetiredReasons(): void
    {
        $unused = [];
        foreach (OrderCorrectionReason::find(WhereClause::whereIn('code', BuiltInCorrectionReasons::retired())) as $reason) {
            // A handful of retired codes at most, so one count each.
            if (null !== $reason->id && 0 === OrderCorrection::countWhere(WhereClause::where('reason_id', $reason->id))) {
                $unused[] = $reason->id;
            }
        }
        if ([] !== $unused) {
            OrderCorrectionReason::deleteWhere(WhereClause::whereIn('id', $unused));
        }
    }
}

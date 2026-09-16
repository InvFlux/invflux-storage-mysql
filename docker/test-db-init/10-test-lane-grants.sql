-- Rights over the whole `invflux_*` namespace, so a suite can create its own lane database
-- (`invflux_test_<lane>`) on demand without a privileged user in the loop.
--
-- The entrypoint runs this only on an empty datadir. That datadir is a tmpfs, so "empty" means
-- every container start — which is the point: the grants come back with the server, and nothing
-- about the dev instance has to be reapplied by hand after a restart or a reboot.
GRANT ALL PRIVILEGES ON `invflux\_%`.* TO 'invflux'@'%';
FLUSH PRIVILEGES;

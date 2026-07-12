# Oomph-PM
A PocketMine-MP adapter for [Oomph](https://github.com/oomph-ac/oomph).

Install this plugin on every PocketMine-MP backend connected through Oomph's
native standalone proxy. It receives Oomph detection events over the standard
Bedrock `ScriptMessagePacket` channel and exposes alerts, logs, punishment
configuration, and cancellable violation/punishment events to other plugins.

No custom PocketMine network interface is required. Configure each backend as a
normal destination of the Oomph proxy and install the built plugin in its
`plugins` directory.

Set `Trusted-Proxy-Addresses` in `config.yml` to the source IP addresses used by
your Oomph proxy. Detection messages from other connections are rejected. The
backend UDP port should also be restricted to those proxy addresses at the
firewall.

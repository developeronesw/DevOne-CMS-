# DevOne Mail

DevOne Mail is provider-independent.

Core owns SMTP configuration, encrypted credential loading, message validation, and the mail service contract.

Runtime adapters own network delivery.

The Local runtime can support direct SMTP delivery. Cloudflare Workers do not expose a general TCP socket API, so Cloudflare requires an HTTP mail-relay adapter instead of attempting raw SMTP from a Worker.

SMTP passwords are decrypted only inside the mail service and are never returned through the settings API.

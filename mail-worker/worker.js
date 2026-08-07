/**
 * Cloudflare Email Worker — lavzen.com mail ingestion for Kargah.
 *
 * Bound to the Email Routing rules for the domain. Every incoming message is
 * streamed as raw RFC822 to Kargah's inbound endpoint, which parses and stores
 * it so it appears in panel.lavzen.com/mail/inbox.
 *
 * Secrets / vars (set via wrangler or the dashboard):
 *   INBOUND_SECRET : must match Kargah's MAILBOX_INBOUND_SECRET
 *   KARGAH_URL     : https://panel.lavzen.com/mail/inbound
 *
 * `message.forward()` is deliberately not called. A rule that both stores and
 * forwards would put every message in two places and make "have I dealt with
 * this" a question with two answers. Forwarding is a routing rule in Cloudflare,
 * not a decision this Worker should be making on the side.
 */
export default {
  async email(message, env) {
    const raw = await new Response(message.raw).arrayBuffer();

    const res = await fetch(env.KARGAH_URL, {
      method: "POST",
      headers: {
        "x-inbound-secret": env.INBOUND_SECRET,
        "x-mail-to": message.to,
        "x-mail-from": message.from,
        "content-type": "message/rfc822",
      },
      body: raw,
    });

    // If Kargah is unreachable, reject so the sender's server retries later
    // rather than silently dropping the mail. Kargah answers 2xx to everything
    // a retry cannot fix, so reaching here means the failure is worth repeating.
    if (!res.ok) {
      message.setReject(`Ingestion failed (${res.status})`);
    }
  },
};

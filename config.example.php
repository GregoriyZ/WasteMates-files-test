<?php
// Copy this file to config.php and fill in real values.
// config.php is git-ignored — it holds live credentials and must never be
// committed. In production it is generated automatically by the GitHub
// Actions deploy workflow from repo secrets (see .github/workflows/deploy.yml).

return [
    // Resend (https://resend.com) — sends over HTTPS, not SMTP. GoDaddy
    // shared hosting blocks outbound SMTP ports entirely (confirmed by
    // testing), so a raw SMTP mailbox can never work from this host.
    // Get an API key from https://resend.com/api-keys after verifying
    // wastemates.com.au as a sending domain (Resend → Domains → Add Domain
    // → add the DNS records it gives you).
    'resend_api_key' => '',

    // Where enquiry emails are sent to/from. mail_from must be an address
    // on the domain verified with Resend (e.g. info@wastemates.com.au once
    // wastemates.com.au is verified).
    'mail_to'     => 'info@wastemates.com.au',
    'mail_from'   => 'info@wastemates.com.au',
    'from_name'   => 'WasteMates Website',

    // Telegram bot — create one via @BotFather to get the token, then message
    // the bot and call https://api.telegram.org/bot<token>/getUpdates to read
    // back the chat_id.
    'telegram_bot_token' => '',
    'telegram_chat_id'   => '',

    // Discord — in the business server, go to the target channel's Settings
    // → Integrations → Webhooks → New Webhook, then "Copy Webhook URL".
    // Anyone with this URL can post to that channel, so treat it as a secret.
    'discord_webhook_url' => '',
];

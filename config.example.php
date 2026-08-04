<?php
// Copy this file to config.php and fill in real values.
// config.php is git-ignored — it holds live credentials and must never be
// committed. In production it is generated automatically by the GitHub
// Actions deploy workflow from repo secrets (see .github/workflows/deploy.yml).

return [
    // Gmail / Google Workspace SMTP for info@wastemates.com.au.
    // smtp_pass must be a 16-character Google App Password, NOT the normal
    // account login password — Google blocks plain-password SMTP login.
    // Generate one at https://myaccount.google.com/apppasswords (needs
    // 2-Step Verification turned on for the account first).
    'smtp_host'   => 'smtp.gmail.com',
    'smtp_port'   => 587,
    'smtp_secure' => 'tls', // 'ssl' or 'tls'
    'smtp_user'   => 'info@wastemates.com.au',
    'smtp_pass'   => '',

    // Where enquiry emails are sent to/from.
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

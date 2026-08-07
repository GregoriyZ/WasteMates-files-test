---
name: submit-enquiry
description: Submit a rubbish removal or waste collection quote enquiry to WasteMates on a visitor's behalf. Use when a user wants a quote for household rubbish removal, green waste, construction waste, deceased estates, or garage/shed cleanouts in WasteMates' Melbourne (VIC) service area.
---

# Submit a WasteMates Enquiry

WasteMates is a Melbourne-based rubbish removal and waste collection business. This skill submits a quote enquiry, which is delivered to the WasteMates team by email, Telegram, and Discord — the same pipeline the on-site contact form uses.

## When to use this skill

The user wants a quote or wants to be contacted about rubbish/waste removal in or around Melbourne, VIC — e.g. household rubbish, green waste, construction waste, a deceased estate clean-out, or a garage/shed clean-out. Check [/service-areas.html](https://wastemates.com.au/service-areas.html) if you need to confirm a specific suburb is covered.

## How it works

Call the A2A endpoint with a JSON-RPC 2.0 `SendMessage` request. Full protocol details, including all `AgentCard`/`AgentSkill` fields, are at [/.well-known/agent-card.json](https://wastemates.com.au/.well-known/agent-card.json).

**Endpoint:** `POST https://wastemates.com.au/a2a.php`
**Content-Type:** `application/json`
**Auth:** none — see [/auth.md](https://wastemates.com.au/auth.md)

### Request

Put the enquiry fields in a `data` part on the message. At least one of `name`, `mobile`, or `email` is required.

```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "method": "SendMessage",
  "params": {
    "message": {
      "messageId": "<uuid>",
      "role": "ROLE_USER",
      "parts": [
        {
          "data": {
            "name": "Jordan Smith",
            "mobile": "0412345678",
            "email": "jordan@example.com",
            "suburb": "Croydon",
            "job": "Household rubbish removal",
            "details": "Old couch and mattress in the garage"
          }
        }
      ]
    }
  }
}
```

All fields except `name`/`mobile`/`email` are optional free text: `suburb`, `job`, `details`. A plain `text` part (instead of `data`) is also accepted and is folded into `details` — useful for a natural-language request, but it won't carry contact info, so expect an `INPUT_REQUIRED` response asking for a name, mobile, or email.

### Response

The call is synchronous — it always returns a terminal (or input-required) `Task` in the same response, never one to poll for later:

- `TASK_STATE_COMPLETED` — the enquiry was delivered through at least one channel (email/Telegram/Discord). `task.status.message` has a human-readable confirmation to relay to the user.
- `TASK_STATE_INPUT_REQUIRED` — missing contact info; `task.status.message` explains what's needed. Send a follow-up `SendMessage` with the same `taskId`/`contextId` and the missing field(s).
- `TASK_STATE_FAILED` — nothing could be delivered (e.g. site misconfiguration or all three channels unreachable). Fall back to directing the user to [https://wastemates.com.au/contact.html](https://wastemates.com.au/contact.html).

## Limitations

- No photo attachments over this interface. If the user has photos of the job, direct them to the web form at [/contact.html](https://wastemates.com.au/contact.html), which supports up to 8 photos.
- No task persistence, polling, streaming, or push notifications — everything resolves in the single response.

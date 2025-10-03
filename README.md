# ESBConnect Leads Collector (WordPress Plugin)

Capture Elementor form submissions that include a client_account_id, sign them with an HMAC SHA-256 signature, and deliver to an AWS Lambda (API Gateway) endpoint for processing and S3 archival.

## Features

- Captures Elementor form submissions
- Requires a hidden `client_account_id` field
- Generates HMAC (SHA-256) signature header (`X-ESB-HMAC`)
- Sends JSON payload to your AWS API Gateway/Lambda URL
- Admin: Submissions list + settings page

## Requirements

- WordPress 6.x
- Elementor / Elementor Pro (Forms)
- PHP 7.4+
- An AWS API Gateway/Lambda endpoint

## Installation

1. Copy the plugin folder `esbconnect-leads-collector` to `wp-content/plugins`
2. Activate the plugin from WordPress Admin → Plugins
3. Go to Admin → ESB Leads → Settings

## Configuration (Admin Settings)

Enter settings first (above), then follow the examples below.

- **HMAC Secret**: Must match your Lambda's environment variable `HMAC_SECRET`
- **Lambda Endpoint URL**: Your deployed API Gateway invoke URL
- **Enable Plugin**: Toggle to start processing submissions

### Elementor Form Setup Examples

1) Add a hidden field named `client_account_id`

![Add hidden field](assets/Screenshot%202025-10-02%20174506.png)

2) Set the default value to the specific client account ID

![Set default value](assets/Screenshot%202025-10-02%20174552.png)

## Request Format

- Method: POST
- Headers:
  - `Content-Type: application/json`
  - `X-ESB-HMAC: <hex sha256 hmac of body>`
- Body (example):

```json
{
  "form_name": "Contact Form",
  "client_account_id": "0018d00450xTL04AAG",
  "fields": {
    "name": "John Doe",
    "email": "john@example.com"
  },
  "site": {
    "home_url": "https://example.com/",
    "site_url": "https://example.com/",
    "blogname": "Example Site"
  },
  "timestamp": 1700000000
}
```

## How HMAC Is Calculated

- Concatenate the exact JSON string sent in the body (no whitespace changes)
- Compute: `hex = HMAC_SHA256(secret=HMAC_SECRET, data=body)`
- Send as header: `X-ESB-HMAC: {hex}`

## Troubleshooting

- Ensure `client_account_id` field exists and has a value
- Verify HMAC secret matches Lambda's `HMAC_SECRET`
- Confirm API URL is reachable (API Gateway invoke URL)
- Check WordPress `debug.log` only when errors occur (plugin logs are minimal by design)

## License

MIT

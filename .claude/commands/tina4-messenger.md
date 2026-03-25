# Set Up Tina4 Email (Send & Receive)

Send and read emails using the built-in Messenger module (SMTP + IMAP).

## Instructions

1. Configure SMTP/IMAP in `.env`
2. Use `Messenger` to send emails
3. Use `Messenger` to read emails via IMAP

## .env

```bash
SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_USERNAME=you@gmail.com
SMTP_PASSWORD=app-password-here
SMTP_FROM=you@gmail.com

IMAP_HOST=imap.gmail.com
IMAP_PORT=993
```

## Send Email

```php
<?php

use Tina4\Messenger;

$mail = new Messenger();

// Plain text
$mail->send("user@example.com", "Hello", "Plain text message");

// HTML
$mail->send(
    "user@example.com",
    "Welcome",
    "<h1>Welcome!</h1><p>Thanks for signing up.</p>",
    true // html
);

// With attachment
$mail->send(
    "user@example.com",
    "Report",
    "See attached.",
    false,
    ["/path/to/report.pdf"]
);

// Multiple recipients, CC, BCC, Reply-To
$mail->send(
    ["alice@test.com", "bob@test.com"],
    "Team Update",
    "...",
    false,
    [],
    ["manager@test.com"],    // cc
    ["archive@test.com"],    // bcc
    "noreply@test.com"       // replyTo
);

// Binary attachment
$mail->send(
    "user@example.com",
    "Image",
    "Here's the image.",
    false,
    [["filename" => "photo.png", "data" => $imageBytes, "mime" => "image/png"]]
);
```

## Read Email (IMAP)

```php
<?php

use Tina4\Messenger;

$mail = new Messenger();

// Get inbox messages (default limit=10)
$messages = $mail->inbox(20);

// Get unread count
$count = $mail->unread();

// Read a specific message by UID
$msg = $mail->read("123");
// Returns: [uid, subject, from, to, cc, date, bodyText, bodyHtml, attachments, headers]

// Search
$results = $mail->search("invoice", "billing@", "2024-01-01", true);

// Mark as read/unread
$mail->markRead("123");
$mail->markUnread("123");

// Delete
$mail->delete("123");

// List folders
$folders = $mail->folders();
```

## Send from a Route

```php
<?php

use Tina4\Router;
use Tina4\Messenger;

Router::post("/api/contact", function ($request, $response) {
    $mail = new Messenger();
    $mail->send(
        "support@myapp.com",
        "Contact: " . $request->body["subject"],
        $request->body["message"],
        false,
        [],
        [],
        [],
        $request->body["email"]
    );
    return $response->json(["sent" => true]);
});
```

## With Templates

```php
<?php

use Tina4\Messenger;
use Tina4\Template;

$html = Template::render("emails/welcome.twig", ["name" => "Alice", "link" => "https://myapp.com"]);
$mail = new Messenger();
$mail->send("alice@test.com", "Welcome!", $html, true);
```

## Test Connection

```php
<?php

$mail = new Messenger();
$mail->testConnection();       // Test SMTP
$mail->testImapConnection();   // Test IMAP
```

## Key Rules

- For slow sends (bulk email), push to a Queue and process asynchronously
- Use app passwords for Gmail (not your real password)
- SMTP uses STARTTLS on port 587, SSL on port 465
- IMAP always uses SSL

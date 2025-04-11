# Custom Webservice Module

This module provides a custom REST endpoint to invite users to content and send them email notifications.

## Features

- Accepts a list of email addresses and a content ID.
- Checks if each user exists by email.
- Creates or updates a node for each user.
- Sends an email to notify the user they’ve been added.

## Endpoint

- **URL:** `/rest/add-user`
- **Method:** `POST`
- **Format:** JSON
- **Authentication:** Requires a logged-in user

## Example Request Body

```json
{
  "emails": "user1@example.com,user2@example.com",
  "content_id": 123
}

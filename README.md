Additional Administrator Manager
================================

Invite, list, edit, suspend and remove additional Plesk administrator accounts from one screen.
Instead of creating an account and sending a password, you invite a person by email. They choose
their own username and password on a secure setup page, and the account is enabled only after that.

Features
--------

* **Invitations by email.** Enter a contact name, an email address and a language. The extension
  creates a disabled account and emails the invitee a personal setup link that is valid for one week
  and can be used once.
* **Self-service setup.** The invitee opens the link, picks a username and password, and can log in
  to Plesk right away. The setup page shows the server's custom logo and applies the password
  strength required by the server's Security Policy.
* **Account overview.** See all additional administrators with their contact name, username, email
  address and comment. Accounts that are suspended or waiting for an invitation to be accepted are
  marked, as are expired invitations.
* **Editing.** Change an account's contact name, email address, password, interface language and
  comment. For a pending invitation, the edit page shows when it expires and lets you resend it,
  which also restarts the one-week validity.
* **Suspend, activate and remove** one or more accounts at once.
* **Per-account language.** The language you choose is used for the account in Plesk, and for the
  invitation email and setup page when the extension is translated into it.
* **Action Log.** Sent, resent and accepted invitations and account edits are recorded in
  **Tools & Settings > Action Log**. Email addresses and passwords are never written to the log.

The extension is available to the main administrator and to additional administrators under
**Server Management > Administrator users**.

Requirements
------------

* Plesk Obsidian 18.0 or later, for Linux. Plesk for Windows is not supported.
* Outgoing email must work on the server. Invitations are sent from the address set as
  `senderAddress` in the `[notification]` section of `panel.ini`, or from
  `no-reply@<server host name>` when it is not set.

Installation
------------

Install the extension in one of these ways.

**From the command line.** Log in to the server as `root` and run:

```sh
plesk bin extension --install-url https://github.com/KasparVd/ext-additional-administrator-manager/archive/refs/heads/main.zip
```

To update an installed copy to the latest version, run the same command with `--upgrade-url` instead of `--install-url`.

**By uploading the ZIP file.**

1. On this page, click **Code > Download ZIP**.
2. In Plesk, go to **Extensions > My Extensions** and click **Upload Extension**.
3. Select the downloaded ZIP file. You don't need to unpack it first.

If the **Upload Extension** button is missing, add these lines to `/usr/local/psa/admin/conf/panel.ini`
on the server and reload the page:

```ini
[ext-catalog]
extensionUpload = true
```

Settings
--------

Optional settings can be added to `/usr/local/psa/admin/conf/panel.ini`:

```ini
[ext-additional-administrator-manager]
forcePowerUser = true
forceAdminRestrictions = true
allowUserEdit = true
helpArticle = "https://example.com/help"
```

| Setting &#xa0;| &#xa0; Default &#xa0; &#xa0; | &#xa0; Description &#xa0; |
|:---|:---|:---|
| `forcePowerUser` &#xa0; | &#xa0; `true` &#xa0; | &#xa0; Invited accounts are created with the Power User view forced. |
| `forceAdminRestrictions` &#xa0; | &#xa0; `true` &#xa0; | &#xa0; Invited accounts are created with Restricted Mode forced. |
| `allowUserEdit` &#xa0; | &#xa0; `true`&#xa0;  | &#xa0; Set to `false` to hide the edit page, so accounts can only be invited, suspended, activated and removed. |
| `helpArticle` &#xa0; | &#xa0; not set &#xa0; | &#xa0; An `http` or `https` URL. When set, the setup page shows a "Need help?" link to it. |
&nbsp;

The `forcePowerUser` and `forceAdminRestrictions` settings apply only to accounts invited after the setting is
changed.
&nbsp;

Limitations
-----------

* Invitations expire after one week. Expired invitations can be resent from the account's edit page.
* The extension currently includes English only. For other languages, the Plesk interface follows
  the account's language, but the invitation email and setup page are in English.
* Usernames can be up to 20 characters: letters, digits, dots, hyphens and underscores.
  `root`, `admin`, `administrator` and `superuser` are reserved.
* An email address can belong to only one additional administrator account.

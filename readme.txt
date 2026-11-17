=== Breakdance Resend Submission ===
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Resend the emails from a Breakdance form submission.

== Description ==

Breakdance stores each form submission (when the form has the "Store Submission"
action) together with a snapshot of the form's settings. This plugin uses that
snapshot to run Breakdance's own Email action again, so the resent message has the
same subject, body, headers and attachments as the original.

Where it shows up:

* Submission screen (Breakdance > Form Submissions > open one): a "Resend Email"
  box in the sidebar. Pick which emails to send, choose whether to use the form
  settings from submission time or the form's current settings, and optionally
  send to a different address instead. Shows the result inline and keeps a
  resend history.
* Submissions list: a "Resend email" row action and a "Resend emails" bulk
  action. These resend every email whose conditions passed, to the original
  recipients, using the settings from submission time.

Notes:

* Only submissions stored by the "Store Submission" action can be resent.
* Uploaded files are attached again if the email has "Attach uploaded files" on
  and the files still exist on disk.
* "Current form settings" reads the form from the page it lives on. If the form
  or page was deleted, that option is unavailable.
* Access follows Breakdance's submission capability
  (`breakdance_form_submission_capability`, default `manage_options`).

== Hooks ==

* `bdrs_capability` (filter): capability required to resend.
* `bdrs_post_type` (filter): submission post type (default `breakdance_form_res`).
* `bdrs_email_before_resend` (filter): `( $email, $submission_id, $index, $form )`, adjust an email before it is sent.
* `bdrs_email_resent` (action): `( $submission_id, $result, $email )`, fires after each send.

Breakdance's own `breakdance_email_message`, `breakdance_email_headers` and
`breakdance_email_attachments` filters also run on resends.

== About ==

Made by IDE Solutions LLC (https://idesolutions.io/). Free to use under the
GPL v3. Source and issues: https://github.com/idesolutions/breakdance-resend-submission

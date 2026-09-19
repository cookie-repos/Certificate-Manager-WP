=== Certificate Manager ===
Contributors: cookieapps
Tags: certificates, verification, qr, pdf, api, csv
Requires at least: 6.0
Requires PHP: 7.4
Tested up to: 6.6
Stable tag: 1.4.3
License: GPL2
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A complete certificate management system for WordPress. Design templates, issue certificates, manage verification, track expirations, and more.

== Description ==

Certificate Manager is a powerful, self-hosted WordPress plugin that helps you design, issue, manage, and verify certificates. Perfect for training organizations, educational institutions, certification bodies, and any enterprise that needs to track and verify achievements.

== Features ==

* **Certificate Template Designer** - Drag-and-drop visual editor for creating professional certificate templates with support for text, images, logos, signatures, seals, QR codes, and more
* **PDF Generation** - Generate high-quality PDF certificates locally using mPDF
* **QR Verification** - Generate QR codes that link to public verification pages
* **Multiple Issuance Methods** - Issue certificates manually, via CSV import, REST API, or scheduled jobs
* **Template Versioning** - Maintain complete version history of templates
* **Certificate Management** - Full CRUD operations with trash support, revocation, replacement, and expiry tracking
* **Email Notifications** - Configurable email delivery with attachment options
* **Expiry Reminders** - Automated reminder emails before certificate expiry
* **Webhook Integration** - Send certificate events to external systems
* **REST API** - Complete REST API for automation and integration
* **CSV Import/Export** - Bulk import and export certificates
* **Public Verification** - Public verification page with configurable field visibility
* **Multisite Support** - Network-wide master templates with site-level isolation
* **W3C Verifiable Credentials** - Cryptographically signed, portable W3C Verifiable Credentials in VC-JWT format
* **Compatible Wallet Issuance** - Optional OpenID4VCI wallet QR code and deep link delivered through a private recipient email link

== Installation ==

1. Upload the plugin folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Navigate to Certificate Manager → Settings to configure the plugin
4. Create your first certificate template
5. Issue your first certificate

== Documentation ==

For complete documentation, visit: https://github.com/cert-manager/certificate-manager/blob/main/README.md

== REST API Documentation ==

API endpoints are documented at: `/wp-json/certificate-manager/v1/documentation`

== Changelog ==

= 1.4.2 =
* Replaced the generic modern verification look with five full public-page themes: Editorial, Registry, Heritage, Folio, and Night.
* Updated the live Settings preview and the verification shortcode so each public verification entry point uses the selected theme.

= 1.4.1 =
* Made wallet import private: recipients request a short-lived wallet link using the email stored against their certificate, without revealing whether it matched.
* Added WordPress email, webhook, or both as configurable delivery for private wallet links and certificate issuance.
* Fixed automatic certificate emails to use a certificate-specific verification link.

= 1.4.0 =
* Added optional OpenID4VCI pre-authorized wallet issuance for valid certificates, with standard issuer and OAuth discovery metadata.
* Added a clear recipient-facing “Add to compatible wallet” experience, one-time cross-device QR code, privacy explanation, and readable credential technical details.

= 1.3.9 =
* Added a portable OpenSSL configuration fallback for creating signed-credential RSA keys.

= 1.3.8 =
* Added an in-admin live verification-page view for each certificate.
* Ensured signed-credential storage is created during upgrades so credential downloads work for existing sites.

= 1.3.7 =
* Reframed public credential messaging around W3C Verifiable Credential 2.0 and cryptographic signing without implying third-party certification.
* Simplified QR badge choices to the compact verification badge designs.

= 1.3.6 =
* Made attached QR codes 40% smaller than their verification badges, with a 24 mm minimum QR size and matched PDF/designer layout.

= 1.3.5 =
* Added a live signed-credential panel to the verification-page preview and valid QR-scan results, including W3C Verifiable Credential details.

= 1.3.4 =
* Added automatic compact and detailed verification-badge variants. Attached badges now change at a 48 mm QR size while preserving QR scanability and the correct group dimensions.

= 1.3.3 =
* Made downloadable signed credentials privacy-first: no email-derived identifier or arbitrary certificate fields are published, and recipient names require explicit issuer opt-in.

= 1.3.2 =
* Fixed PDF downloads failing when a certificate contains large attached verification badges or other large HTML content.

= 1.3.1 =
* Added attached verification-badge QR layouts with left and right placement, proportionate sizing and PDF support.

= 1.3.0 =
* Added signed W3C Verifiable Credentials using the W3C Verifiable Credentials 2.0 data model and VC-JWT proof format.
* Added recipient credential downloads, an issuer profile, public signing keys and live credential-status metadata.

= 1.2.67 =
* Added template-specific CSV layouts and bulk certificate issuance from completed CSV files.

= 1.2.66 =
* Fixed cached log styling, formatted stored JSON details for readability, and replaced remaining browser prompts and alerts with plugin dialogs and notices.

= 1.2.65 =
* Kept long audit and operational log details contained and readable within their panels.

= 1.2.64 =
* Replaced certificate action prompts with consistent, accessible admin dialogs for replacement, revocation and webhook delivery.

= 1.2.63 =
* Added quick certificate register filters for expiring, expired, revoked and replaced records.

= 1.2.62 =
* Added a per-certificate manual webhook trigger with reusable certificate-specific webhook selection.

= 1.2.61 =
* Fixed generated PDF filenames so certificate and recipient placeholders use the issued certificate data.

= 1.2.60 =
* Consolidated certificate actions into compact menus and added search with pagination.

= 1.2.59 =
* Added expiry countdowns, configurable renewal warnings and automatic expired status updates.

= 1.2.58 =
* Fixed media opacity so it is applied directly to images in generated PDFs.

= 1.2.57 =
* Prevented Enter from issuing a certificate and enabled multi-line custom text fields.

= 1.2.56 =
* Added an opacity control for media elements in certificate templates and exported PDFs.

= 1.2.55 =
* Added square image frames, size readouts, element locks, keyboard deletion and administrator-approved image choices.

= 1.2.54 =
* Fixed cached Variables page scripts and made variable creation an inline, type-aware form.

= 1.2.53 =
* Fixed exported PDF bold text and added image variables with Media Library selection during certificate issue.
* Added multi-line address support in the certificate issue form.

= 1.2.52 =
* Fixed the production entry point bootstrap namespace used during activation.

= 1.2.51 =
* Removed production diagnostic file writes and rebuilt the release package structure.

= 1.2.50 =
* Fixed verification settings class loading and removed redundant diagnostic build files.

= 1.2.49 =
* Made QR verification links permanent and redirected them to the configured destination.

= 1.2.48 =
* Redirected legacy QR verification links to the configured verification page.

= 1.2.47 =
* Added a configurable verification destination for certificate QR codes and verification links.

= 1.2.46 =
* Made verification page creation optional and added shortcode setup guidance.

= 1.2.45 =
* Added verification links and privacy-safe certificate request webhooks.

= 1.2.44 =
* Fixed certificate issuance when a webhook has custom payload fields.

= 1.2.43 =
* Added per-webhook certificate payload field selection.

= 1.2.42 =
* Repeated status watermarks and improved template layer and webhook controls.

= 1.2.41 =
* Added per-template webhook selection for certificate issuance.

= 1.2.40 =
* Automatically initialize the certificate number on first issue.

= 1.2.39 =
* Moved webhook configuration into the Webhooks section.

= 1.2.38 =
* Fixed the plugin ZIP structure for WordPress installation.

= 1.2.37 =
* Simplified the Integrations screen to webhooks only.

= 1.2.36 =
* Replaced certificate email delivery with configurable certificate-issued webhooks
* Included recipient email and name in webhook payloads for Zapier and other automations

= 1.2.35 =
* Added an administrator-only testing cleanup area with two-step permanent certificate and template deletion
* Preserved audit and operational logs during cleanup and protected templates while certificates remain

= 1.2.34 =
* Added watermarked current-status PDFs and administrator-only original certificate views

= 1.2.33 =
* Show meaningful WordPress usernames in the audit trail and retain actor details for future records

= 1.2.32 =
* Added role-based Certificate Manager permissions and the Certificate Issuer WordPress role
* Applied separate permissions to template editing, publishing, deletion and certificate actions

= 1.2.31 =
* Added a live verification layout and accent preview in Settings

= 1.2.30 =
* Split verification page settings into independently selectable layouts and accent colours
* Made Modern, Classic and Minimal verification layouts visually distinct across result and lookup views

= 1.2.29 =
* Applied the selected verification style to manually placed certificate-verification shortcodes

= 1.2.28 =
* Redesigned the public verification experience and added Modern, Classic and Minimal style choices

= 1.2.27 =
* Removed internal QR padding so generated codes fill their selected designer square

= 1.2.26 =
* Matched generated QR image dimensions to the designer and showed template titles in the certificate register
* Added a public certificate-number lookup form with per-IP verification rate limiting

= 1.2.25 =
* Added a complete CSV export of the certificate register, lifecycle information, snapshots and stored template values

= 1.2.24 =
* Recorded the actual local issue time when an issue date is selected
* Preserved explicitly supplied timestamps while normalising date-only API and CSV inputs

= 1.2.23 =
* Rendered Learning Outcomes as one bullet point per entered line in previews and PDFs
* Kept outcomes readable with left-aligned bullet lists
* Added guidance to the certificate issue form for multi-line outcomes

= 1.2.22 =
* Fixed the critical error when opening a certificate verification link
* Added a complete public verification result for valid, expired, revoked and replaced certificates
* Switched generated QR codes to compact verification-token links
* Enforced a 24 mm minimum QR size and four-module quiet zone
* Adapted QR error correction and image resolution to its physical size and data length

= 1.2.21 =
* Locked QR code elements to a square aspect ratio in the designer
* Kept width and height synchronised when resizing QR codes or editing their dimensions
* Corrected previously saved rectangular QR boxes when rendering certificates

= 1.2.20 =
* Matched PDF point sizes to the designer's CSS pixel sizes
* Restored QR codes when the verification page setting has not yet been synchronised
* Embedded generated QR images directly in PDFs instead of relying on an HTTP image request
* Corrected verification QR links to use the public verifier's certificate number parameter

= 1.2.19 =
* Matched generated PDF text sizing to the template designer scale
* Kept media, QR codes, lines and SVG shapes at their saved absolute positions
* Preserved uploaded image proportions using the same contain behaviour as the designer
* Regenerated certificate PDFs whenever View is clicked so renderer fixes appear immediately

= 1.2.18 =
* Fixed certificate revocation failing because its database fields were missing
* Added schema migration for revocation history, PDF paths and verification counters
* Verified the stored status is revoked before reporting a successful action
* Returned real database errors instead of silently leaving certificates active

= 1.2.17 =
* Replaced the certificate View JSON alert with the rendered certificate PDF
* Opened certificate previews inline in a separate browser tab
* Generated and stored the PDF automatically when a certificate has not been rendered yet
* Protected certificate preview links with WordPress capabilities and nonces

= 1.2.16 =
* Added smart alignment guides between every text, QR, media and shape element
* Snapped nearby left, centre, right, top, middle and bottom positions while dragging
* Added snapping and guide lines for certificate edges and page centres
* Calculated alignment using the visible bounds of rotated elements

= 1.2.15 =
* Prevented enlarged and signature-style text from being clipped by its editing box
* Allowed multi-line text to extend naturally while retaining its selectable design area
* Matched the improved text line height in generated PDFs

= 1.2.14 =
* Allowed media, lines and shapes to extend beyond certificate edges for intentional cropping
* Kept a small portion of off-page elements visible so they remain recoverable in the designer
* Preserved negative and over-page positions when templates are saved

= 1.2.13 =
* Added rotation controls for media, lines and every shape
* Added quick -90, 0 and 90 degree rotation presets
* Kept rotated items inside certificate boundaries in the designer and on save
* Applied saved rotation consistently in sample previews and generated PDFs

= 1.2.12 =
* Removed the grey placeholder background and dashed border from populated media
* Preserved transparent PNG backgrounds in both editing and sample preview modes

= 1.2.11 =
* Added read-only sample-data preview mode to the template designer
* Added Preview actions to saved template cards
* Added realistic random names, courses, dates, certificate numbers and learning outcomes
* Added automatic sample values for custom template variables

= 1.2.10 =
* Proportionally remapped every element when switching between landscape and portrait
* Automatically brought older out-of-bounds elements back inside the certificate when opened
* Enforced certificate boundaries while adding elements or entering dimensions manually
* Added server-side boundary enforcement before templates are saved

= 1.2.9 =
* Replaced the long shape list with a compact Shapes catalogue
* Moved variables into a searchable overlay catalogue to keep page controls visible
* Collapsed advanced size, position and layer controls in the properties panel
* Made both designer sidebars stay in view while working on a tall certificate canvas

= 1.2.8 =
* Removed all preinstalled Pixabay artwork from the plugin package
* Added an onboarding prompt for the site owner's Pixabay API key
* Kept Pixabay search opt-in and imports selected images into WordPress Media

= 1.2.7 =
* Withdrawn development build; its preinstalled stock artwork was removed in 1.2.8

= 1.2.6 =
* Added bundled Great Vibes and Allura signature fonts plus additional serif and sans-serif choices
* Added rounded rectangles, circles and ovals, triangles, diamonds, stars, hexagons and ribbons
* Added adjustable background image opacity with matching designer and PDF output

= 1.2.5 =
* Added a global default template that is automatically selected when issuing certificates
* Added the multi-line learning_outcomes variable for per-certificate outcomes and learning objectives
* Added automatic data migrations when updating an existing installation

= 1.2.4 =
* Bundled mPDF 8.3.1 and all pinned runtime dependencies in the plugin release
* Added a plugin-private dependency autoloader and writable PDF cache directory
* Updated System Status to report the bundled PDF engine and version

= 1.2.3 =
* Added one-click horizontal, vertical and full-page centring controls
* Added magnetic centre snapping and visual alignment guides while dragging elements

= 1.2.2 =
* Fixed the release archive layout for WordPress and WordPress Playground installation

= 1.2.1 =
* Added a drag-and-drop certificate designer with media, variables, QR codes, shapes, page backgrounds, resizing and template versioning
* Added manual certificate issuance with template-specific variable fields and expiry controls
* Fixed template publishing, variable mapping, certificate numbering and rendering integration
* Fixed Certificate Manager role access to template management

= 1.0.0 =
* Initial release
* Complete certificate management system
* Template designer with drag-and-drop editor
* PDF generation with mPDF
* QR code generation and verification
* Manual, CSV, API, and scheduled issuance
* Email notifications and expiry reminders
* Webhook integration
* Public verification page
* Multisite support

== Upgrade Notice ==

= 1.0.0 =
Initial release. Backup your site before upgrading.

== Screenshots ==

1. Certificate Template Designer
2. Certificate Management Screen
3. Public Verification Page
4. Dashboard Statistics
5. System Status Screen

== Frequently Asked Questions ==

= How do I verify a certificate? =

Visit the public verification page and enter the certificate number or scan the QR code.

= Can I customize the verification page? =

Yes, the verification page is a standard WordPress page that you can edit using your preferred page builder.

= Does this plugin send data to external services? =

Certificate generation, PDF rendering, and verification happen locally on your server. If a site administrator configures and uses the optional Pixabay search, the search term, page number, image type, safety filter and the site's Pixabay API key are sent to Pixabay. Selected images are then downloaded into the site's WordPress Media Library. No certificate or recipient data is sent to Pixabay.

= What happens if I uninstall the plugin? =

By default, all certificate data is preserved. You can optionally choose to delete all data during uninstall.

= Is this plugin GDPR compliant? =

The plugin provides controls for data retention and does not send certificate or recipient data to Pixabay. Site administrators should still review their own privacy obligations before enabling any external integration.

== License ==

Certificate Manager is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, either version 2 of the License, or any later version.

Certificate Manager is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.

You should have received a copy of the GNU General Public License along with this program. If not, see https://www.gnu.org/licenses/gpl-2.0.html.

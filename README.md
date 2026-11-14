# MMe Core

**All-in-One Optimization & Security plugin by MMe.**

MMe Core is a comprehensive WordPress plugin that combines essential optimization, security, and media management features into a single, lightweight, and easy-to-use package.

## Features

### Media & Optimization
* **Classic Editor**: Restores the classic WordPress editor.
* **TinyMCE Plugins**: Enhances the classic editor with advanced TinyMCE plugins (Table, SearchReplace, VisualBlocks, etc.).
* **Optimize Image SEO**: Automatically cleans filenames and sets image Alt Text and Title upon upload.
* **Auto Rename Images**: Automatically renames uploaded images based on a custom pattern (e.g., `{domain}-{date}-{random}`).
* **Convert to WebP**: Automatically converts uploaded images (JPEG/PNG) to next-gen WebP format to save space and improve load speed.
* **Image Watermark**: Automatically adds a customizable watermark to uploaded images.
* **Media Replacement**: Easily replace existing media files without changing their IDs or breaking existing links.
* **Remove Image Attributes**: Cleans up unwanted `img` tag attributes in the post content.
* **Auto Upload External Images**: Automatically downloads and saves external images to your media library when pasting content from other sources.

### Security & Enhancements
* **Limit Login Attempts**: Protects against brute-force attacks by blocking IPs after too many failed login attempts.
* **Custom Login URL**: Change the default `wp-login.php` to a custom slug to hide the login page from bots.
* **Custom Login Logo**: Replace the default WordPress logo on the login screen with your brand's logo.
* **Disable XML-RPC**: Blocks XML-RPC to prevent DDoS and brute-force attacks.
* **Disable REST API User Enumeration**: Prevents bots from scraping your user list via the REST API.
* **Disable Author Archives**: Blocks author queries to hide usernames.
* **Disable Comments**: Completely disables comments across the entire site.
* **Remove WP Version**: Removes the WordPress version meta tag and hashes the WordPress version in core asset URLs to hide your footprint. Plugin and theme asset versions (`?ver=`) are kept, so CSS/JS updates are never stuck in browser or CDN caches.
* **Clean Up Admin Bar**: Removes unnecessary items (WP Logo, Comments, Updates) from the top admin bar for a cleaner interface.

### Performance
* **Cache Warmer**: Requests a list of URLs you choose once a day (at a time you pick) and on demand with **Run now**, so the page cache is built before visitors arrive. Made for WordOps (nginx FastCGI / Redis page cache): requests can go to 127.0.0.1 to skip Cloudflare/CDN. URLs can be imported from the sitemap (Rank Math, Yoast or WordPress core).

### Maintenance
* **Updates**: Compares the installed version with the `main` branch on GitHub, shows the changelog and updates through the normal WordPress updater (also listed on the Plugins page). **Reinstall from GitHub** overwrites the files when needed.
* **Logs**: Activity log of updates, cache warming, backups and PHP fatal errors, stored in `uploads/wpmme-logs/` (not readable from the web).

## Installation

1. Download the latest `.zip` file from the [Releases](https://github.com/hoangmme/wpmme) or the `main` branch.
2. Go to **Plugins > Add New > Upload Plugin** in your WordPress dashboard.
3. Upload the `.zip` file and click **Install Now**, then **Activate**.
4. Go to **MMe Core Settings** in the admin menu to configure the features.

## Updating

MMe Core updates itself from GitHub:
1. Go to **MMe Core → Updates** and click **Check for updates** (WordPress also checks automatically every few hours).
2. When a newer version is on the `main` branch, click **Update to x.y.z**. The update also appears on the Plugins page, where auto-updates can be turned on.
3. If an update fails, **Reinstall from GitHub** downloads the branch and overwrites the plugin files.

### Publishing an update
1. Raise `Version` (and the build) in the `wpmme.php` header and `WPMME_VERSION`.
2. Add the changes under **Changelog** below.
3. Push to `main`. Sites see it within a few hours, or right away with **Check for updates**.

## Changelog

### 1.1.1
- Fix: **Clean Up Admin Notices** hid the new Application Password after clicking *Add New Application Password*, so the password could not be copied. It now hides only nags: notices that answer your own action (new Application Password, Settings saved, form errors, Post updated, inline notices) stay visible, and nothing is hidden on the Profile, Users, Plugins, Updates and Site Health screens.

### 1.1.0
- New: **Cache Warmer** page. Choose the URLs (or import them from the sitemap), run them daily at a set time, or click **Run now** to see each page's cache status (HIT / MISS / BYPASS) live. Requests can go through 127.0.0.1 to skip Cloudflare on WordOps servers.
- New: **Updates** page, like MMe Elementor Kit: installed vs latest version, changelog, **Check for updates**, update through the WordPress updater (also shown on the Plugins page, with a menu badge), and **Reinstall from GitHub**.
- New: **Logs** page: updates, cache warming, backups and PHP fatal errors, with filters.
- Changed: MMe Core menu split into Settings, Cache Warmer, Updates and Logs.

### 1.0.1
- Fix: **Remove WP Version** stripped `?ver=` from every CSS/JS URL, so browsers and Cloudflare kept old files after plugin updates. Now only the WordPress version is hashed; plugin and theme versions are kept.

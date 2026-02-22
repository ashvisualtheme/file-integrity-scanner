# 🛡️ File Integrity Scanner Plugin for OJS 3.5.x

## **Uncompromising Security for Your OJS Installation**

This essential plugin dramatically strengthens your OJS security posture by proactively scanning your core application and plugin files. It uses **cryptographic hash comparison** against known official baselines to instantly detect unauthorized modifications, additions, or deletions that could signal file corruption or a security breach.

---

## ✨ Key Features at a Glance

| Feature                           | Description                                                                                                                                                                                                                                                                                                          |
| :-------------------------------- | :------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 🕵️ **Proactive Change Detection** | Automatically calculates local **SHA256 hashes** and compares them to the official, version-specific baselines stored remotely.                                                                                                                                                                                      |
| 🎯 **Pinpoint Accuracy**          | Validates integrity for both the **OJS Core files** and individual **Plugins**, ensuring nothing is left unchecked.                                                                                                                                                                                                  |
| 📧 **Critical Alerts**            | Sends a detailed email notification to the site contact address, summarizing all detected files that were **Modified**, **Added**, or **Deleted** (both against the official baseline and for locally monitored files).                                                                                              |
| ⏱️ **Scheduled Automation**       | Registers a task to run a full integrity scan automatically **once every 24 hours**.                                                                                                                                                                                                                                 |
| ✨ **Smart Cache System**         | Caches hash baselines for efficiency and **automatically cleans up orphaned and outdated cache files** after OJS or plugin upgrades, ensuring fresh baselines are always used.                                                                                                                                       |
| 📝 **Manual Excludes**            | Allows administrators to specify a list of files or directories to be **monitored for local changes but excluded from baseline comparison**. This helps reduce false positives from intentional modifications (e.g., `config.inc.php`) while still alerting you to any unauthorized changes to these critical files. |

---

### **🔍 Detected Security Issue Types**

The scan precisely identifies deviations from both the official baseline and locally monitored files:

#### Baseline Deviations

- **⚠️ Modified:** A core/plugin file exists, but its hash does not match the official baseline (indicates a change or corruption).
- **🚨 Added:** A file exists locally but is **not** present in the official baseline (a potential indicator of malicious file uploads).
- **❌ Deleted:** A file present in the official baseline is missing from the local installation (potential file system corruption or removal by an attacker).

#### Local Changes (for Monitored Exclusions)

These alerts apply to files/directories you've manually excluded from baseline comparison (like `config.inc.php`) but still want to monitor for any changes.

- **⚠️ Monitored Modified:** An excluded file has changed locally since the last scan.
- **🚨 Monitored Added:** A new file has been found within an excluded directory (e.g., a new file in `public/`).
- **❌ Monitored Deleted:** A file previously present in an excluded directory is now missing.

---

## ⚙️ Requirements & Installation

### System Requirements

- **OJS version:** **3.4.x** (requires PKP library scheduled task support).
- **PHP 8.1 and above:** Must support `hash_file('sha256', ...)` and allow `file_get_contents(...)` for downloading remote JSON files.
- **Acron Plugin:** Must be enabled to allow the automated daily scan to run.
- **Email Configuration:** Email sending `(smtp)` must be properly configured in `config.inc.php` to receive scan alerts.
- **Administrator Account** for manage and excecutions.

### Installation in 5 Simple Steps

1.  ⬇️ Download the latest release from the **plugin's release page**.
2.  🔑 Log in to your OJS dashboard as a **Site Administrator**.
3.  ➡️ Navigate to **Website Settings > Plugins > Upload a New Plugin**.
4.  📤 Upload the downloaded `.tar.gz` file.
5.  ✅ Once installation is complete, **enable** the plugin under the **Generic Plugins** tab.

---

## 🛠️ Usage and Administration

The plugin is designed for automated security, but administrators retain full control over immediate actions and cache management.

### **Automatic Daily Schedule**

The integrity scan runs automatically **once per day** using the OJS scheduled tasks feature (Acron plugin).

- You will **only receive an email notification** if the scan detects any file changes. If your file system is clean, **no email** is sent.

### **Manual Actions (Instant Control)**

1.  Navigate to **Website Settings > Plugins**.
2.  Find the **File Integrity Plugin** and click the actions arrow.
3.  You have two powerful actions:
    - **⚡ Run Manual Scan:** Instantly execute a full, on-demand scan. This is ideal after major updates or when suspicious activity is suspected.
    - **🗑️ Clear Hash Cache:** Deletes all cached baseline JSON files. While the plugin **automatically removes outdated cache files** after software upgrades, this manual action is useful if you suspect the cache is corrupt or want to force a fresh download for all items on the next scan.

### **Configuring Settings**

You can configure the plugin to exclude specific files or directories from the baseline comparison and add additional email recipients for scan notifications.

1.  Navigate to **Website Settings > Plugins**.
2.  Find the **File Integrity Plugin** and click the actions arrow, then select **Settings**.
3.  In the settings modal, you will find two fields:
    - **Manual Excludes**: Enter the paths of files or directories you wish to exclude from the baseline comparison, one path per line. These paths will be monitored for local changes instead. Paths should be relative to your OJS root directory (e.g., `.htaccess` or `plugins/generic/myCustomPlugin`).
    - **Additional Notification Emails**: Enter additional email addresses that should receive the scan reports. You can separate multiple emails with a comma, space, or new line.

    **Example `Manual Excludes`:**

    ```
    .htaccess
    google7tebgr5hcdtth.html
    plugins/themes/my-custom-theme/
    ```

    **Example `Additional Notification Emails`:**

    ```
    admin@myjournal.com, it.lead@myjournal.com
    security.officer@university.edu
    ```

4.  Click **Save**. Your settings will be applied to the next scan.

---

## 🧑‍💻 Development, Support, and The Hash Ecosystem

### Developed and Maintained by **AshVisualTheme**

We are committed to maintaining the security and effectiveness of this critical tool.

📧 **Dedicated Support:** For technical support or inquiries regarding custom OJS development, please contact us at `support@ashvisual.com`.

### **Hash Baseline Source & Contribution**

The plugin is powered by a robust security ecosystem. It fetches the official, cryptographically verified baselines from our dedicated public GitHub repository:

**Baseline Source URL**:
`https://github.com/ashvisualtheme/hash-repo`

**Want to add your plugin to our ecosystem?** If you maintain a widely-used OJS plugin, please review our comprehensive contribution guidelines directly in the [**Hash Repository**](https://github.com/ashvisualtheme/hash-repo) to have your official baseline included!

---

## 🎨 Transform Your Journal: Discover Professional OJS Themes

As specialists in OJS infrastructure, **AshVisualTheme** also develops high-quality, professional themes.

Stop using default OJS templates. **Elevate your reader and author experience today!**

➡️ **View Our Professional Themes in Action:** <https://demo-ojs.ashvisual.com>

---

## License

This plugin is released under the **GNU General Public License v3**. See the `LICENSE` file for full terms.

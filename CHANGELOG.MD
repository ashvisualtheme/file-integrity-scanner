# 📋 Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## `[1.4.0.0]` - 2025-11-02

### 🚀 Added

- **OJS 3.4 Compatibility**: Full support for Open Journal Systems version 3.4.x.

### 🔧 Changed

- **Internal Refinements**: Updated internal code to align with changes in the OJS 3.4 application core, ensuring stable and reliable performance.
- **Dependency Updates**: Adjusted class imports and method calls to match the latest PKP library structure.

## `[1.3.0.0]` - 2025-10-31

### ✨ Compatibility

Open Journal Systems (OJS) 3.3.x

### 🚀 Added

- **Monitored Exclusions**: The system now actively monitors critical files like `config.inc.php` and the `public/` directory for any changes, additions, or deletions, and reports these activities.
- **Orphaned Cache Cleanup**: A new function to automatically detect and remove outdated cache files left behind from previous application versions or uninstalled plugins.
- **"All Clear" Notifications**: The system will now send an email notification even when no issues are found, confirming that the scan completed successfully.
- **Enhanced Email Reports**: Email reports are now more detailed, with clear descriptions for each category of issue and a dedicated section for reporting changes to monitored files.

### 🔧 Changed

- **Exclusion Logic Overhaul**: The method for excluding files has been completely revised, now distinguishing between "Permanent Exclusions" (e.g., `cache/`) which are never scanned, and "Monitored Exclusions" which are watched for changes.
- **Improved Email Structure**: The content and structure of the notification email have been significantly improved for better readability and clarity.

### 🔒 Security

- **Hardened Baseline Fetching**: The connection to GitHub for downloading hash baselines now enforces stricter SSL/TLS verification to protect against potential man-in-the-middle attacks.
- **Secure Cache Integrity**: All cache files are now signed with an HMAC hash to ensure the integrity of the cache and prevent tampering.
- **Stricter File Permissions**: Cache files and directories are now created with more secure file permissions (`0600` for files, `0700` for directories) to restrict access.
- **Improved Baseline Validation**: Downloaded baseline files are now validated to ensure correct JSON formatting and hash data, preventing errors from corrupted files.

---

## `[1.0.0.0-alpha]` - 2025-10-24

### 🚀 Key features

- **Core File Integrity Scanning**: Introduced the core functionality to scan and compare installation files against official checksums to detect changes.
- **Plugin Validation**: The ability to validate plugin files against their specific hash baselines to reduce false-positive reports.
- **Email Alerts for Discrepancies**: Implemented automatic email notifications sent to the site contact when any modified, added, or deleted files are detected.
- **Configurable Exclusion List**: An option for administrators to add a list of files or directories to be ignored during the scan.
- **Scheduled Automatic Scans**: The ability to run scans automatically based on a predefined schedule.
- **Baseline Caching**: The system caches downloaded hash baselines to reduce repetitive requests to external servers.

# 📋 Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## `[1.5.0.0]` - 2026-08-06

### 🚀 Key features

- **Core File Integrity Scanning**: Introduced the core functionality to scan and compare installation files against official checksums to detect changes.
- **Plugin Validation**: The ability to validate plugin files against their specific hash baselines to reduce false-positive reports.
- **Email Alerts for Discrepancies**: Implemented automatic email notifications sent to the site contact when any modified, added, or deleted files are detected.
- **Configurable Exclusion List**: An option for administrators to add a list of files or directories to be ignored during the scan.
- **Scheduled Automatic Scans**: The ability to run scans automatically based on a predefined schedule.
- **Baseline Caching**: The system caches downloaded hash baselines to reduce repetitive requests to external servers.
- **Configurable scan time**: The daily scan hour is now selectable from the plugin's Settings UI (a 00:00–23:00 dropdown, server time), instead of being hardcoded to 03:00 in the source code. Defaults to 03:00 if never configured.

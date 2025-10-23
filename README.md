# CFDB7 Stats Viewer

A standalone PHP reporting tool for **Contact Form 7 + CFDB7** submissions, designed to visualize the number of form submissions over time — **without relying on cookies or Google Analytics**.

This script connects directly to your WordPress database, reads entries from the CFDB7 table, and displays a **line chart** (powered by Chart.js) showing how many form submissions occurred within a chosen time range (7, 15, 28, or 90 days).  
You can view totals for all forms or filter by a specific form ID.

---

## 📊 Features

- **No cookies or tracking consent required** — fully server-side data analysis.  
- **Aggregated line chart** by day (local WordPress timezone).  
- **Selectable range:** 7, 15, 28, or 90 days.  
- **Filter by form:** view all submissions combined or focus on one Contact Form 7 form.  
- **Automatic schema detection:** works with various CFDB7 table versions (`wp_db7_forms`, `wp_cf7dbplugin_submits`, etc.).  
- **Auto-detection of timestamps** (MySQL datetime, epoch seconds, or epoch milliseconds).  
- **Automatic extraction of form ID** from CFDB7 payloads (JSON or serialized PHP).  
- **Secure admin access only** (`manage_options` capability required).  
- **Timezone-aware daily aggregation** using your WordPress timezone setting.  
- **No dependencies** — a single self-contained PHP file with Chart.js loaded via CDN.

---

## 🚀 Installation

1. Copy the file `cfdb7-stats.php` into your **WordPress root directory** (the same folder where `wp-load.php` is located).  
   > If you use Bedrock or a custom structure, you can place it elsewhere; the script automatically searches for `wp-load.php` up to six levels up.

2. Log in to WordPress as an **administrator**.

3. Access the script directly from your browser: `https://yourdomain.com/cfdb7-stats.php`

You’ll see a dashboard with:
- A date range selector (7 / 15 / 28 / 90 days)
- A form selector (all forms or a specific one)
- A line chart of daily submission counts

---

## 🔐 Authentication and Access

The script requires you to be logged in to WordPress as an **administrator**.  
If you’re logged in but see “Access denied,” make sure:

- You open the script on the **same domain and protocol** (e.g. `https://www.` vs `http://`).
- You’re not viewing a cached version (exclude the URL from CDN/Varnish cache).
- WordPress is properly bootstrapped — the script must find the correct `wp-load.php`.

The included bootstrap logic:
- Searches for `wp-load.php` automatically (works with classic WP and Bedrock setups).
- Forces alignment of **host and scheme** with your `siteurl` (so login cookies are valid).
- Redirects to `wp-login.php` if you’re not authenticated.
- Checks that the current user has `manage_options` capability.

---

## ⚙️ Configuration Notes

- **Supported tables:**  
- `wp_db7_forms` (CFDB7 standard)  
- `wp_cf7dbplugin_submits` (legacy CF7 DB plugin)  

- **Detected columns:**  
- Timestamp: `created_on`, `created_at`, `submit_time`, `submitted_at`, etc.  
- Form ID: `form_id`, `form_post_id`, or extracted from payload JSON/serialized data.  
- Payload: `form_value`, `data`, `submitted_data`, `meta_data`.  

- **Timezone:** The chart uses the timezone defined in *Settings → General → Timezone* in WordPress.

---

## 🧩 Dependencies

- **WordPress 5.0+**
- **Contact Form 7**
- **CFDB7**
- **Chart.js** (loaded automatically from CDN)

No database schema modifications or plugins are required.

---

## 🧠 Use Cases

- Verify a **perceived drop in form submissions** (without relying on GA4 or cookies).  
- Monitor **lead generation trends** across multiple Contact Form 7 forms.  
- Compare recent performance (e.g. last 7 vs 28 days).  
- Perform **quick sanity checks** on CF7 forms without accessing phpMyAdmin.

---

## 🛠️ Troubleshooting

| Issue | Possible cause | Solution |
|-------|----------------|-----------|
| “Access denied” | Wrong domain or scheme (e.g. `http` instead of `https`) | Open the script using the same URL used to access the WP admin. |
| “Cannot find wp-load.php” | Script placed too deep in a subfolder | Move the file closer to the WordPress root or adjust `$root` path. |
| Empty chart | No submissions found in the selected range | Try a longer range (90 days) or confirm CFDB7 is recording submissions. |

---

## 📄 License

MIT License — free to use and modify.  
This tool simply reads your own data stored by CFDB7 and does not transmit any information externally.

---

## 💡 Optional Enhancements

- Convert it into a **WordPress admin page** (under “Tools → CFDB7 Stats”).  
- Add **multi-form overlay** (one line per form).  
- Add **CSV export** of daily counts.  
- Add **alerts or Slack notifications** when submissions drop sharply.

---

**Author:**  
Developed by Paolo Niccolò Giubelli <paoloniccolo.giubelli@gmail.com>
For internal use to analyze Contact Form 7 activity without user tracking.

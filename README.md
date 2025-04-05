# One-Time Private Links

**Converts any WordPress page/post into a one-time private link with full control and visibility.**

## 🔥 Features

- ⏰ Expiration timer in hours
- 🔒 Token-based access
- 🚫 Auto-expire after first use
- 🌐 Redirects to the actual page once accessed
- 🕵️ Logs access attempts with IP & User Agent
- 🧑‍💻 Admin-friendly UI using TailwindCSS
- 🔐 Secure nonce verification

## 🛠 Installation

1. Download the plugin ZIP: `one-time-private-links-pro.zip`
2. Go to your WordPress admin dashboard
3. Navigate to **Plugins > Add New > Upload Plugin**
4. Upload the ZIP and click **Install Now**
5. Activate the plugin
6. Go to **Private Links** in the admin sidebar

## 🚀 How to Use

1. Enter a page/post URL
2. Set an expiration time (in hours)
3. Click **Generate**
4. Copy and share the private link

Once the link is accessed:
- The visitor is redirected to the original page
- The link becomes inactive
- Access is logged for auditing

## 📋 Access Logs

View all access attempts with:
- IP address
- User agent
- Timestamp

Find it under **Private Links > Access Logs**

## 🔐 Security

- Nonce verification to prevent CSRF
- Sanitized input fields
- Token is unique and securely generated

## 🙋‍♂️ Why I Built This

Most plugins I found either:
- Require the user to register/login
- Temporarily create a user account

This plugin removes that hassle. Just generate, share, done.

## 📎 Changelog

### 2.0
- AJAX-based generation
- Improved UX & UI
- Access logs added

---

Built with ❤️ by Samuel Peters

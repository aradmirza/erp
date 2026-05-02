# Sodai Lagbe ERP — Setup Guide

## নতুন Server এ Install করার নিয়ম

### ধাপ ১: Code Clone করুন
```bash
git clone https://github.com/aradmirza/erp.git
cd erp
```

### ধাপ ২: .env ফাইল তৈরি করুন
```bash
cp .env.example .env
```
তারপর `.env` ফাইল edit করে সঠিক value বসান:
- `DB_HOST` — আপনার database server host
- `DB_NAME` — Database এর নাম
- `DB_USER` — Database user
- `DB_PASSWORD` — Database password
- `SMS_API_KEY` — sms.net.bd API key
- `ADMIN_SECRET_PIN` — Admin delete PIN

### ধাপ ৩: Database Import করুন
1. phpMyAdmin বা database tool খুলুন
2. নতুন database তৈরি করুন (`.env` এ যে নাম দিয়েছেন)
3. `.sql` backup file import করুন (server এ আলাদাভাবে রাখা আছে)

### ধাপ ৪: Upload Folder Permission (Linux server)
```bash
chmod 755 uploads/profiles
chmod 755 uploads/receipts
chmod 755 uploads/settings
chmod 755 admin/uploads
chmod 755 admin/uploads/videos
```

### ধাপ ৫: .htaccess দিয়ে .env Protect করুন
`.htaccess` ফাইলে নিচের rule আছে কিনা confirm করুন:
```apache
<Files ".env">
    Order allow,deny
    Deny from all
</Files>
```

### ধাপ ৬: Site Test করুন
Browser এ আপনার domain খুলুন এবং admin login করুন।

---

## ⚠️ Security Checklist

- [ ] `.env` ফাইল সঠিকভাবে set করা আছে
- [ ] `.env` ফাইল কখনো GitHub এ push করবেন না
- [ ] Database backup নিয়মিত রাখুন (server এর বাইরে)
- [ ] SMS API key গোপন রাখুন
- [ ] Admin PIN শুধু owner জানবেন

---

## 📁 Project Structure

```
/                      ← Shareholder Portal (root)
├── config.php         ← .env loader (সব ফাইল এটি include করে)
├── .env               ← ⚠️ GitHub এ নেই — manually তৈরি করতে হবে
├── .env.example       ← Template (এটা GitHub এ আছে)
├── db.php             ← Database connection
├── login.php          ← Shareholder login + OTP
├── dashboard.php      ← Main dashboard
├── transactions.php   ← Financial history
├── user_kpi.php       ← KPI panel
├── user_votes.php     ← Voting
└── admin/             ← Admin Panel
    ├── db.php         ← Admin DB connection
    ├── login.php      ← Admin login
    └── ...
```

---

## 🔒 Security Warning

⚠️ **এই git repository তে সংবেদনশীল তথ্য আছে — সাবধানতার সাথে পরিচালনা করুন:**

1. **`.env` ফাইল কখনো GitHub এ push করবেন না**
2. **Pull request বা code share করার সময় credential leak চেক করুন**
3. **Live server এ deploy করার সময় .env ফাইল আলাদাভাবে server এ তৈরি করুন**
4. **Repository সবসময় Private রাখুন**

---

## 📞 Owner Contact

- **Owner:** Mirza Rafiuzzaman Arad
- **Company:** Sodai Lagbe
- **Location:** Tangail, Bangladesh

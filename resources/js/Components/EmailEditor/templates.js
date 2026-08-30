// ─── Built-in email templates ─────────────────────────────────────────────────

export const EMAIL_TEMPLATES = [
    {
        id: 'welcome',
        name: 'Welcome Email',
        category: 'Onboarding',
        subject: 'Welcome to {{company_name}}, {{contact.first_name}}!',
        body: `<h2 style="margin:0 0 12px;font-family:sans-serif;color:#111;">Welcome aboard, {{contact.first_name}}! 🎉</h2>
<p style="margin:0 0 12px;font-family:sans-serif;font-size:15px;line-height:1.6;color:#333;">We're thrilled to have you join us. Your account is all set and ready to go.</p>
<p style="margin:0 0 12px;font-family:sans-serif;font-size:15px;line-height:1.6;color:#333;">Here's what you can do next:</p>
<p style="margin:0 0 8px;font-family:sans-serif;font-size:15px;line-height:1.6;color:#333;">✅ Complete your profile</p>
<p style="margin:0 0 8px;font-family:sans-serif;font-size:15px;line-height:1.6;color:#333;">✅ Explore our features</p>
<p style="margin:0 0 16px;font-family:sans-serif;font-size:15px;line-height:1.6;color:#333;">✅ Reach out if you need any help</p>
<div style="text-align:center;margin:16px 0;"><a href="#" style="display:inline-block;padding:12px 24px;background:#2563eb;color:#fff;text-decoration:none;border-radius:6px;font-family:sans-serif;font-size:15px;font-weight:600;">Get Started</a></div>
<p style="margin:0 0 12px;font-family:sans-serif;font-size:15px;line-height:1.6;color:#333;">If you have any questions, just reply to this email — we're always happy to help.</p>`,
    },
    {
        id: 'promo',
        name: 'Promotional Offer',
        category: 'Marketing',
        subject: '🔥 Exclusive offer just for you, {{contact.first_name}}',
        body: `<h2 style="margin:0 0 12px;font-family:sans-serif;color:#111;">A special offer, just for you</h2>
<p style="margin:0 0 12px;font-family:sans-serif;font-size:15px;line-height:1.6;color:#333;">Hi {{contact.first_name}}, we wanted to reward our loyal customers with something special.</p>
<p style="margin:0 0 12px;font-family:sans-serif;font-size:15px;line-height:1.6;color:#333;">Use code <strong>SAVE20</strong> for 20% off your next order!</p>
<p style="margin:0 0 12px;font-family:sans-serif;font-size:15px;line-height:1.6;color:#333;">This offer expires in 48 hours, so don't miss out.</p>
<div style="text-align:center;margin:16px 0;"><a href="#" style="display:inline-block;padding:12px 24px;background:#f59e0b;color:#fff;text-decoration:none;border-radius:6px;font-family:sans-serif;font-size:15px;font-weight:600;">Claim Your Discount</a></div>`,
    },
    {
        id: 'newsletter',
        name: 'Newsletter',
        category: 'Content',
        subject: 'Your monthly update — {{month}} edition',
        body: `<h2 style="margin:0 0 4px;font-family:sans-serif;color:#111;">Monthly Newsletter</h2>
<p style="margin:0 0 20px;font-family:sans-serif;font-size:13px;color:#6b7280;">The latest updates, tips, and news</p>
<hr style="border:none;border-top:1px solid #e5e7eb;margin:20px 0;" />
<h3 style="margin:0 0 8px;font-family:sans-serif;color:#111;">What's new this month</h3>
<p style="margin:0 0 12px;font-family:sans-serif;font-size:15px;line-height:1.6;color:#333;">We've been busy building exciting new features and improvements. Here's a quick roundup of everything that's new.</p>
<h3 style="margin:0 0 8px;font-family:sans-serif;color:#111;">Featured article</h3>
<p style="margin:0 0 12px;font-family:sans-serif;font-size:15px;line-height:1.6;color:#333;">Check out our latest guide on getting the most out of your account. It covers tips and tricks our power users swear by.</p>
<div style="text-align:center;margin:16px 0;"><a href="#" style="display:inline-block;padding:12px 24px;background:#2563eb;color:#fff;text-decoration:none;border-radius:6px;font-family:sans-serif;font-size:15px;font-weight:600;">Read the Guide</a></div>
<hr style="border:none;border-top:1px solid #e5e7eb;margin:20px 0;" />
<p style="margin:0;font-family:sans-serif;font-size:13px;color:#9ca3af;text-align:center;">You're receiving this because you subscribed to our newsletter.</p>`,
    },
    {
        id: 'announcement',
        name: 'Product Announcement',
        category: 'Marketing',
        subject: 'Introducing [Feature Name] — Now available',
        body: `<h2 style="margin:0 0 12px;font-family:sans-serif;color:#111;">We're launching something new 🚀</h2>
<p style="margin:0 0 12px;font-family:sans-serif;font-size:15px;line-height:1.6;color:#333;">Hi {{contact.first_name}}, today is an exciting day. We've been working hard on something we think you're going to love.</p>
<h3 style="margin:0 0 8px;font-family:sans-serif;color:#111;">Introducing [Feature Name]</h3>
<p style="margin:0 0 12px;font-family:sans-serif;font-size:15px;line-height:1.6;color:#333;">A brief description of what it does and why it's amazing for users like you.</p>
<p style="margin:0 0 12px;font-family:sans-serif;font-size:15px;line-height:1.6;color:#333;">Here's why you'll love it:</p>
<p style="margin:0 0 8px;font-family:sans-serif;font-size:15px;line-height:1.6;color:#333;">⚡ Benefit one — saves you time</p>
<p style="margin:0 0 8px;font-family:sans-serif;font-size:15px;line-height:1.6;color:#333;">🎯 Benefit two — improves results</p>
<p style="margin:0 0 16px;font-family:sans-serif;font-size:15px;line-height:1.6;color:#333;">🔒 Benefit three — keeps things secure</p>
<div style="text-align:center;margin:16px 0;"><a href="#" style="display:inline-block;padding:12px 24px;background:#2563eb;color:#fff;text-decoration:none;border-radius:6px;font-family:sans-serif;font-size:15px;font-weight:600;">Try It Now</a></div>`,
    },
    {
        id: 'reengagement',
        name: 'Re-engagement',
        category: 'Retention',
        subject: 'We miss you, {{contact.first_name}} 👋',
        body: `<h2 style="margin:0 0 12px;font-family:sans-serif;color:#111;">It's been a while, {{contact.first_name}}</h2>
<p style="margin:0 0 12px;font-family:sans-serif;font-size:15px;line-height:1.6;color:#333;">We noticed you haven't been around lately and wanted to check in. Is everything okay? We'd love to have you back.</p>
<p style="margin:0 0 12px;font-family:sans-serif;font-size:15px;line-height:1.6;color:#333;">Since your last visit, we've added some great new features we think you'll enjoy:</p>
<p style="margin:0 0 8px;font-family:sans-serif;font-size:15px;line-height:1.6;color:#333;">✨ New feature 1</p>
<p style="margin:0 0 8px;font-family:sans-serif;font-size:15px;line-height:1.6;color:#333;">✨ New feature 2</p>
<p style="margin:0 0 16px;font-family:sans-serif;font-size:15px;line-height:1.6;color:#333;">✨ New feature 3</p>
<div style="text-align:center;margin:16px 0;"><a href="#" style="display:inline-block;padding:12px 24px;background:#10b981;color:#fff;text-decoration:none;border-radius:6px;font-family:sans-serif;font-size:15px;font-weight:600;">Come Back & Explore</a></div>
<p style="margin:0 0 12px;font-family:sans-serif;font-size:13px;color:#6b7280;text-align:center;">Don't want to hear from us? <a href="#" style="color:#6b7280;">Unsubscribe</a></p>`,
    },
    {
        id: 'event',
        name: 'Event Invitation',
        category: 'Events',
        subject: "You're invited: [Event Name] on [Date]",
        body: `<h2 style="margin:0 0 4px;font-family:sans-serif;color:#111;">You're Invited!</h2>
<p style="margin:0 0 20px;font-family:sans-serif;font-size:13px;color:#6b7280;">A special event just for you, {{contact.first_name}}</p>
<h3 style="margin:0 0 8px;font-family:sans-serif;color:#111;">[Event Name]</h3>
<p style="margin:0 0 4px;font-family:sans-serif;font-size:15px;line-height:1.6;color:#333;">📅 [Date] at [Time]</p>
<p style="margin:0 0 16px;font-family:sans-serif;font-size:15px;line-height:1.6;color:#333;">📍 [Location / Online link]</p>
<p style="margin:0 0 12px;font-family:sans-serif;font-size:15px;line-height:1.6;color:#333;">We'd love to see you there. This event is a great opportunity to [reason to attend].</p>
<div style="text-align:center;margin:16px 0;"><a href="#" style="display:inline-block;padding:12px 24px;background:#7c3aed;color:#fff;text-decoration:none;border-radius:6px;font-family:sans-serif;font-size:15px;font-weight:600;">RSVP Now</a></div>
<p style="margin:0;font-family:sans-serif;font-size:13px;color:#6b7280;">Spots are limited — secure yours today.</p>`,
    },
];

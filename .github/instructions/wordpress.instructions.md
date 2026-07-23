---
applyTo: 'wp-content/plugins/**,wp-content/themes/**,**/*.php,**/*.inc,**/*.js,**/*.jsx,**/*.ts,**/*.tsx,**/*.css,**/*.scss,**/*.json'
description: 'WordPress coding rules: secure, performant, testable, WP standards compliant'
---

# WordPress Dev Rules

## 1. Core Principles
- Extend WP only via hooks/filters; never modify core
- Use unique prefixes or namespaces (avoid globals)
- Enqueue assets only via WP APIs (no inline scripts/styles)
- Ensure i18n for all user-facing strings
- Prefer small functions, separation of concerns

## 2. Security (always required)
- Escape output: `esc_*`, sanitize input: `sanitize_*`
- Use nonces + capability checks for mutations (AJAX/REST/forms)
- Use `$wpdb->prepare()` (no raw SQL)
- Validate uploads via WP APIs
- REST: always `permission_callback` + validated args schema

## 3. Coding Standards
- Follow WPCS + WordPress PHP/JS conventions
- PHP 8.0+ compatible unless stated otherwise
- Strict comparisons where appropriate
- Use DocBlocks for public APIs

## 4. i18n
- Wrap strings: `__()`, `esc_html__()` etc. with text domain
- Load text domain in plugin/theme bootstrap

## 5. Performance
- Avoid heavy work on `init` unless necessary
- Use transients/object cache for expensive queries
- Conditional asset loading only where needed

## 6. Admin / REST / UI
- Settings API with sanitization callbacks
- REST routes must define args validation + permission checks
- Use WP APIs for admin notices and screens

## 7. Assets
```php
add_action('wp_enqueue_scripts', function () {
  wp_enqueue_style('af', plugins_url('assets/style.css', __FILE__));
  wp_enqueue_script('af', plugins_url('assets/app.js', __FILE__), [], null, true);
});

# Inertia Migration Matrix

| Legacy (API / Vue SPA) | Web route | Controller | Inertia Page | Status |
| --- | --- | --- | --- | --- |
| `POST /api/login` | `POST /login` | `Web\Auth\LoginController` | `Auth/Login` | done |
| `POST /api/register` | `POST /register` | `Web\Auth\RegisterController` | `Auth/Register` | done |
| `POST /api/auth/vk` | `GET /auth/vk/redirect` + callback | `Web\Auth\VkOAuthController` | — | done |
| `POST /api/auth/yandex` | `GET /auth/yandex/redirect` + callback | `Web\Auth\YandexOAuthController` | — | done |
| `GET /api/get-permissions` | shared prop `auth.permissions` | `HandleInertiaRequests` | — | done |
| Vue Dashboard widgets | `GET /dashboard` | `Web\DashboardController` | `Dashboard/Index` | done |
| Admin shell `/cw-page/` | `GET /cw-page` | `Web\Admin\AdminController` | `AdminLayout` | done |
| Blog posts/categories/tags | `/cw-page/blog/*` | `Web\Admin\Blog\*` | `Admin/Blog/*` | done |
| Subscribers, plans, payments, extra-limits | `/cw-page/subscribers`, `/plans`, `/payments`, `/extra-limits` | `Web\Admin\SubscriberController`, `PlanController`, `PaymentController`, `ExtraLimitController` | `Admin/Subscribers/*`, `Admin/Plans/*`, `Admin/Payments/*`, `Admin/ExtraLimits/*` | done (PR3) |
| Coupons, users, roles, sent emails | `/cw-page/coupons`, `/users`, `/roles`, `/sent-emails` | `Web\Admin\CouponController`, `UserController`, `RoleController`, `SentEmailController` | `Admin/Coupons/*`, `Admin/Users/*`, `Admin/Roles/*`, `Admin/SentEmails/*` | done (PR4) |
| Services admin (feedbacks, repricer, AI) | `/cw-page/services/*` | `Web\Admin\*` | `Admin/*` | pending (Phase 2 PR5–6) |
| Nuxt subscriber platform (auth, profile, subscriptions, payments) | `/panel`, `/panel/user/profile`, `/panel/user/history` | `Web/Subscriber/*`, `Web/Auth/*` | `Subscriber/Panel/*`, `Subscriber/Profile/*`, `Subscriber/Payments/*`, `Auth/ForgotPassword`, `Auth/ResetPassword` | done (Phase 3a) |
| Subscriber tools infrastructure | `routes/subscriber-tools.php`, shared components, `useToolPoll` | `Web/Subscriber/SubscriberToolController` | `components/subscriber/tools/*` | done (Phase 3b.0) |
| Public blog | `/blog`, `/blog/{slug}`, `/blog/sitemap.xml` | `Web/Blog/*` | `Blog/Index`, `Blog/Show` | done (Phase 3b.1) |
| WB Feedbacks (4 pages) | `/panel/wb/feedbacks`, `/panel/wb/feedbacks/clients/{client}`, `.../templates`, `.../products/{product}` | `Web/Subscriber/Wb/Feedbacks/*` | `Subscriber/Wb/Feedbacks/*` | done (Phase 3b.2) |
| Ozon Feedbacks (2 pages) | `/panel/oz/feedbacks`, `/panel/oz/feedbacks/cabinets/{cabinet}` | `Web/Subscriber/Oz/Feedbacks/*` | `Subscriber/Oz/Feedbacks/*` | done (Phase 3b.3) |
| WB Price Calc V3 (2 pages) | `/panel/wb/price-calc`, `/panel/wb/price-calc/cabinets/{cabinet}` | `Web/Subscriber/Wb/PriceCalc/*` | `Subscriber/Wb/PriceCalc/*` | done (Phase 3b.4) |
| Ozon Price Calc (2 pages) | `/panel/oz/price-calc`, `/panel/oz/price-calc/cabinets/{cabinet}` | `Web/Subscriber/Oz/PriceCalc/*` | `Subscriber/Oz/PriceCalc/*` | done (Phase 3b.5) |
| WB Repricer v1 (5 pages) | `/panel/wb/repricer`, `/panel/wb/repricer/cabinets/{cabinet}`, `.../time`, `.../stocks` | `Web/Subscriber/Wb/Repricer/*` | `Subscriber/Wb/Repricer/*` | done (Phase 3b.6 v1) |
| WB Profitability (2 pages) | `/panel/wb/profitability`, `/panel/wb/profitability/cabinets/{cabinet}` | `Web/Subscriber/Wb/Profitability/*` | `Subscriber/Wb/Profitability/*` | done (Phase 3b.7) |
| WB AI Cabinet Analyzer (2 pages) | `/panel/wb/ai-cabinet-analyzer`, `/panel/wb/ai-cabinet-analyzer/cabinets/{cabinet}` | `Web/Subscriber/Wb/AiCabinetAnalyzer/*` | `Subscriber/Wb/AiCabinetAnalyzer/*` | done (Phase 3b.8) |
| WB Promo Calculator (1 page) | `/panel/wb/promocalculator` | `Web/Subscriber/Wb/PromoCalculator/*` | `Subscriber/Wb/PromoCalculator/Index` | done (Phase 3b.9) |
| AI Marketplace (1 page) | `/panel/ai` | `Web/Subscriber/Ai/*` | `Subscriber/Ai/Index` | done (Phase 3b.10) |
| Legacy API routes | — | — | — | pending (Phase 4) |
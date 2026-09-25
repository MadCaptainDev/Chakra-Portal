<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Proposal;
use App\Models\User;
use App\Support\ProposalBlocks;
use Illuminate\Database\Seeder;

/**
 * The Print Bazzar proposal, as designed in Claude Design ("Print Bazzar
 * Proposal"), so the module is live on day one and there is a complete
 * template -- every block type in use -- to duplicate for the next client.
 *
 * Run once on deploy: php artisan db:seed --class=ProposalSeeder
 *
 * Idempotent and non-destructive: if a proposal with this title already
 * exists it is left exactly as it is, so re-running never overwrites edits
 * made in the admin.
 */
class ProposalSeeder extends Seeder
{
    public const TITLE = 'Print Bazzar — E-Commerce & Print Business Platform';

    public function run(): void
    {
        if (Proposal::where('title', self::TITLE)->exists()) {
            $this->command?->info('Print Bazzar proposal already exists -- left untouched.');

            return;
        }

        Proposal::create([
            'title' => self::TITLE,
            'client_id' => Client::where('name', 'like', '%Print Baz%')->value('id'),
            'status' => Proposal::STATUS_DRAFT,
            'created_by_id' => User::where('role', User::ROLE_ADMIN)->orderBy('id')->value('id'),
            'sections' => ProposalBlocks::normalizeSections(self::sections()),
        ]);

        $this->command?->info('Print Bazzar proposal created.');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function sections(): array
    {
        $s = fn (string $key, string $number, string $title, array $blocks, bool $newPage = false) => [
            'key' => $key,
            'type' => 'section',
            'data' => ['number' => $number, 'title' => $title, 'new_page' => $newPage, 'blocks' => $blocks],
        ];
        $p = fn (string $text, string $tone = 'default') => ['type' => 'paragraph', 'text' => $text, 'tone' => $tone];
        $ul = fn (array $items) => ['type' => 'list', 'items' => $items];
        $h3 = fn (string $text) => ['type' => 'subheading', 'text' => $text];
        $grid = fn (array $rows) => ['type' => 'table', 'header' => [], 'rows' => $rows];
        $moduleTable = fn (array $rows) => [
            'type' => 'table', 'header' => ['Module', 'Scope'], 'rows' => $rows,
            'first_col_bold' => true, 'first_col_width' => 30,
        ];
        $cost = fn (string $heading, array $rows) => [
            'type' => 'table', 'header' => [$heading, 'Cost'], 'rows' => $rows,
            'last_col_right' => true, 'last_row_bold' => true,
        ];
        $cell = fn (string $text = '', bool $loop = false) => ['text' => $text, 'loop' => $loop];

        return [
            ['key' => 'cover', 'type' => 'cover', 'data' => [
                'eyebrow' => 'Project Proposal',
                'brand' => 'app_studio',
                'prepared_for_label' => 'Prepared for',
                'client_logo' => 'images/proposals/print-bazzar.png',
                'title' => 'Print Bazzar',
                'subtitle' => 'Scalable E-Commerce & Print Business Management Platform',
                'prepared_by' => 'Chakra App Studio',
                'client_name' => 'Print Bazzar',
                'date_label' => 'September 2026',
            ]],

            $s('how-to-read', '', '', [[
                'type' => 'legend',
                'title' => 'How to read this proposal',
                'items' => [
                    ['tag' => 'requirement', 'label' => 'Client Requirement', 'note' => 'requested by Print Bazzar'],
                    ['tag' => 'recommendation', 'label' => 'Proposed Recommendation', 'note' => "Chakra App Studio's implementation view"],
                    ['tag' => 'optional', 'label' => 'Optional / Future', 'note' => 'not in current scope'],
                ],
            ]], true),

            $s('executive-summary', '01', 'Executive Summary', [
                $p('Print Bazzar intends to build a technology-driven printing e-commerce platform for printing, custom products, branding, signage, packaging, corporate gifts and graphic design. Chakra App Studio has reviewed the requirements and proposes a phased platform that combines a customer-facing store with the internal systems needed to run the print business: orders, quotations, design approval, production, inventory, invoicing and reporting.'),
                $p('The work is structured in three phases. Phase 1 launches the e-commerce foundation. Phase 2 brings business operations onto the same system. Phase 3 strengthens the platform with advanced capabilities and optimisation. Each phase delivers usable value and builds on the one before it.'),
            ]),

            $s('about-the-project', '02', 'About the Project', [
                $p("This project is significantly larger than a conventional e-commerce website. A standard store sells fixed products at fixed prices. Print Bazzar's products are configured by the customer, priced from those choices, accompanied by artwork files, and then move through design, approval and production before they ship."),
                ['type' => 'table', 'header' => ['Conventional e-commerce website', 'Print Bazzar platform'], 'first_col_width' => 50, 'rows' => [
                    ['Fixed product variants', 'Dynamic product configuration per product type'],
                    ['Fixed price list', 'Pricing engine based on quantity, size, material, finishing and more'],
                    ['No file handling', 'Artwork upload with preflight checks'],
                    ['Order ends at dispatch', 'Design, approval, production, QC, packing, dispatch and delivery workflow'],
                    ['Basic order list', 'Job cards, quotations, staff, departments, inventory, GST invoicing, audit logs and reports'],
                ]],
            ]),

            $s('understanding', '03', 'Understanding of Print Bazzar', [
                $p("Print Bazzar's stated objective is a professional, scalable and mobile-first e-commerce website and backend management system. The larger goal is a technology-driven printing e-commerce platform rather than a simple printing website."),
                ['type' => 'cards', 'variant' => 'labelled', 'columns' => 2, 'items' => [
                    ['label' => 'Customer journey', 'text' => 'Select Product → Configure → Upload Artwork → Preflight → Pay → Track Order'],
                    ['label' => 'Internal workflow', 'text' => 'Order → Design → Approval → Production → QC → Packing → Dispatch → Delivery → Audit'],
                ]],
                $p('Product categories in scope: Printing, Custom Products, Branding, Signage, Packaging, Corporate Gifts and Graphic Design. Reference websites (Vistaprint.in, Printmine.in, Printo.in) are used as market inspiration only.'),
            ], true),

            $s('objectives', '04', 'Project Objectives', [
                $ul([
                    'Launch a mobile-first, conversion-focused store for configurable print products.',
                    'Let customers configure products, see pricing, upload artwork and pay online in one flow.',
                    'Manage online and offline orders, quotations and job cards in one system.',
                    'Give each department and staff member a clear view of their work.',
                    'Track inventory, payments and GST invoices accurately.',
                    'Provide reports for sales, operations and staff performance.',
                    'Build on an architecture that can scale and support future mobile apps.',
                ]),
            ]),

            $s('proposed-solution', '05', 'Proposed Solution', [
                $p('Chakra App Studio proposes a single, centralised Laravel application with three layers: a customer storefront, a role-based staff and admin panel, and an API layer prepared for future mobile apps. All layers share one database, so an order placed online is immediately visible to sales, design, production and accounts.'),
                ['type' => 'table', 'header' => ['Component', 'Who uses it', 'Purpose'], 'first_col_bold' => true, 'rows' => [
                    ['Storefront', 'Customers', 'Browse, configure, upload artwork, pay, track'],
                    ['Customer account', 'Customers', 'Orders, invoices, approvals, reorder, addresses'],
                    ['Operations panel', 'Admin and staff', 'Orders, quotations, job cards, production, inventory, reports'],
                    ['API layer', 'Future mobile apps', 'Prepared for Flutter apps in a later phase'],
                ]],
            ], true),

            $s('journey', '06', 'Business & Customer Journey', [
                ['type' => 'flow', 'tone' => 'light', 'steps' => ['Select Product', 'Configure', 'Upload Artwork', 'Preflight', 'Pay', 'Track Order']],
                ['type' => 'flow', 'tone' => 'dark', 'steps' => ['Order', 'Design', 'Approval', 'Production', 'QC', 'Packing', 'Dispatch', 'Delivery', 'Audit']],
                $p('The customer journey (top) is delivered in Phase 1. The internal workflow (bottom) is delivered in Phase 2, with every step recorded against the order for traceability.'),
            ]),

            $s('order-flow-map', '06', 'Order Flow Map', [
                $p('How a single order moves between the customer, the platform and the Print Bazzar team. Dashed steps are review or correction loops.'),
                ['type' => 'swimlane', 'lanes' => ['Customer', 'Platform', 'Print Bazzar team'], 'legend' => true, 'rows' => [
                    [$cell('Select product & configure'), $cell('Calculates price from selected options'), $cell()],
                    [$cell('Upload artwork'), $cell('Runs preflight: **Print Ready** or **Quality Warning**'), $cell()],
                    [$cell('Re-upload artwork if a warning is shown', true), $cell(), $cell('Manual review where human verification is needed', true)],
                    [$cell('Pay via Razorpay (UPI, cards, net banking)'), $cell('Verifies payment; creates unique Order ID / Job ID'), $cell('Offline orders and accepted quotations enter here')],
                    [$cell(), $cell(), $cell('Design — job card assigned to designer')],
                    [$cell('Approve design or request changes'), $cell(), $cell('Revise design if changes are requested', true)],
                    [$cell(), $cell(), $cell('Production')],
                    [$cell(), $cell(), $cell('QC — failed items return to production')],
                    [$cell(), $cell(), $cell('Packing → Dispatch')],
                    [$cell('Track order; access GST invoice'), $cell('Status updates; GST invoice generated'), $cell()],
                    [$cell('Receive delivery; reorder anytime'), $cell('Order completed; full audit log recorded'), $cell('Delivered')],
                ]],
                ['type' => 'note', 'tag' => 'recommendation', 'text' => "The review and correction loops shown here are Chakra App Studio's proposed handling. Final workflow stages will be configured with Print Bazzar during discovery."],
            ], true),

            $s('architecture', '07', 'Proposed System Architecture', [
                ['type' => 'architecture', 'layers' => [
                    ['label' => 'Access layer', 'style' => 'light', 'columns' => 4, 'items' => [
                        ['text' => 'Storefront (Livewire)'], ['text' => 'Customer Account'], ['text' => 'Admin & Staff Panel'],
                        ['text' => 'Future Flutter Apps (API)', 'dashed' => true],
                    ]],
                    ['label' => 'Application layer — Laravel', 'style' => 'dark', 'columns' => 4, 'items' => array_map(fn ($t) => ['text' => $t], [
                        'Catalogue & Configurator', 'Pricing Engine', 'Artwork & Preflight', 'Cart & Checkout',
                        'Orders & Job Cards', 'Quotations', 'Production Workflow', 'Staff & Roles',
                        'Inventory', 'Payments & GST', 'Reports', 'Audit Logs',
                    ])],
                    ['label' => 'Data & services', 'style' => 'outline', 'columns' => 3, 'items' => [
                        ['text' => 'MySQL Database'], ['text' => 'Application / Server File Storage'], ['text' => 'Razorpay Payment Gateway'],
                    ]],
                ]],
            ], true),

            $s('technology-stack', '08', 'Technology Stack', [
                $p('The following stack has been agreed for this project.'),
                ['type' => 'stack', 'items' => [
                    ['category' => 'Frontend', 'name' => 'Laravel Livewire', 'logo' => 'livewire'],
                    ['category' => 'Backend', 'name' => 'Laravel', 'logo' => 'laravel'],
                    ['category' => 'Database', 'name' => 'MySQL', 'logo' => 'mysql'],
                    ['category' => 'Admin', 'name' => 'Laravel-based admin', 'logo' => 'laravel'],
                    ['category' => 'Payment', 'name' => 'Razorpay', 'logo' => 'razorpay'],
                    ['category' => 'Future mobile', 'name' => 'Flutter', 'logo' => 'flutter'],
                    ['category' => 'Hosting', 'name' => 'To be finalised', 'logo' => ''],
                    ['category' => 'Storage', 'name' => 'Server storage', 'logo' => ''],
                ]],
                $p('Hosting will be finalised based on infrastructure requirements and discussion with Print Bazzar. Artwork and media will initially use application/server storage; object storage can be introduced as the platform scales.'),
            ], true),

            $s('phase-1', '09', 'Phase 1 — E-Commerce Foundation', [
                $p('Phase 1 launches the customer-facing store and the admin tools needed to operate it.'),
                $moduleTable([
                    ['Storefront', 'Responsive website, homepage, categories, product catalogue, search, filtering, product detail pages'],
                    ['Product configuration', 'Quantity, size, material, printing, finishing and product-specific options'],
                    ['Pricing', 'Dynamic pricing, bulk/quantity pricing'],
                    ['Artwork', 'Artwork upload and preflight capability'],
                    ['Purchase', 'Cart, Buy Now, checkout, Razorpay payment integration'],
                    ['Customer account', 'Registration/login, order history, order tracking, invoice access, reorder, address management, GST/billing information'],
                    ['Basic admin', 'Product, category, pricing, banner/content, coupon/discount, order and customer management'],
                ]),
            ], true),

            $s('phase-2', '10', 'Phase 2 — Business Operations', [
                $p("Phase 2 moves Print Bazzar's internal operations onto the platform."),
                $moduleTable([
                    ['Orders', 'Advanced order management, online and offline order creation'],
                    ['Quotations', 'Quotation management, convert quotation to order'],
                    ['Workflow', 'Job cards, design workflow, design approval, production workflow, customizable workflow stages'],
                    ['People', 'Department management, staff management, role-based access'],
                    ['Tasks', 'Task management, work queues, priorities, deadlines'],
                    ['Fulfilment', 'QC, packing, dispatch workflows, delivery tracking'],
                    ['Inventory (basic)', 'Stock in, stock out, consumption, wastage, closing stock, low-stock alerts'],
                    ['Finance', 'Payment tracking, GST invoices'],
                    ['Control & visibility', 'Complete audit logs, department-wise dashboards, staff dashboards, operational reports'],
                ]),
            ], true),

            $s('phase-3', '11', 'Phase 3 — Advanced Platform & Optimization', [
                $p('Phase 3 builds on real usage data from Phases 1 and 2. Final scope will be confirmed with Print Bazzar before the phase begins.'),
                $ul([
                    'Advanced artwork preflight capabilities',
                    'Advanced pricing rule management, where technically appropriate',
                    'Advanced reporting, analytics and operational insights',
                    'Performance optimisation and scalability improvements',
                    'Automation opportunities and production efficiency improvements',
                    'Platform hardening',
                    'Additional integrations, if mutually agreed',
                ]),
            ], true),

            $s('customer-experience', '12', 'Customer Experience', [
                $p('The storefront will be modern, professional, conversion-focused and mobile-first, designed so that normal customers can configure print products without assistance. The visual design will be developed as a unique Print Bazzar experience; reference websites inform market expectations but will not be copied.'),
                $h3('Customer account'),
                $ul([
                    'Registration and login via email/password and mobile OTP',
                    'Orders, order tracking, invoices and payments',
                    'Artwork upload and re-upload, design approvals',
                    'Reorder, saved addresses, profile, previous quotations and orders',
                ]),
            ]),

            $s('configuration-pricing', '13', 'Product Configuration & Pricing Engine', [
                $h3('Configuration'),
                $p('Different printing products need different options. The system will use a flexible configuration model instead of hardcoding options for each product. Configurable parameters may include quantity, size, material, GSM, printing, finishing, design service and other product-specific options.'),
                ['type' => 'callout', 'tone' => 'brand', 'text' => 'Product-specific configuration parameters will be finalized during the detailed product and business-rule discovery phase.'],
                $h3('Pricing'),
                $p('The pricing engine will be structured to support configurable pricing rules based on the finalized product and commercial logic. Where applicable, it will consider:'),
                $grid([
                    ['Base price', 'Quantity pricing', 'Size pricing', 'Material pricing'],
                    ['Finishing charges', 'Design charges', 'GST', 'Delivery charges'],
                    ['Bulk discounts', 'Dealer / B2B pricing', '', ''],
                ]),
                $p('Admin will be able to manage pricing rules without developer intervention wherever technically feasible.'),
            ], true),

            $s('artwork-preflight', '14', 'Artwork Upload & Preflight', [
                $p('Customers upload artwork during ordering. The system runs checks and returns a clear result such as [ok]Print Ready[/ok] or [warn]Quality Warning — Please check your artwork[/warn].'),
                $grid([
                    ['File type', 'File size', 'Resolution / DPI', 'Dimensions'],
                    ['Bleed', 'Safe margin', 'Image quality', 'RGB / CMYK'],
                    ['Missing fonts (where detectable)', 'Transparency', 'Print area', ''],
                ]),
                $p('Automated checks will be implemented where technically feasible, with manual review available for cases requiring human verification.'),
            ], true),

            $s('order-job-management', '15', 'Order & Job Management', [
                $p('The system will support online customer orders and offline orders created by internal staff. Every order receives a unique Order ID / Job ID.'),
                ['type' => 'flow', 'tone' => 'line', 'steps' => ['Order Received', 'Payment', 'Design', 'Design Approval', 'Production', 'QC', 'Packing', 'Dispatch', 'Delivered', 'Completed']],
                $p('Workflow stages will be configurable where practical.'),
            ]),

            $s('quotation-management', '16', 'Quotation Management', [
                $p('Sales staff will be able to:'),
                $ul([
                    'Select a customer, add and configure products',
                    'Apply applicable pricing and permitted discounts',
                    'Add delivery and calculate GST',
                    'Generate and share/send the quotation',
                    'Convert an accepted quotation into an order',
                ]),
                $p('The channel used to send quotations will be agreed separately.'),
            ], true),

            $s('production-workflow', '17', 'Production Workflow', [
                $p('Each order produces a job card that moves through design, approval, production, QC, packing and dispatch. Work queues, priorities and deadlines help each department see what needs attention. Every status change is logged with the staff member and time, giving a complete audit trail per job.'),
            ]),

            $s('staff-departments', '18', 'Staff & Department Management', [
                $p('A centralised Laravel application with role-based access. Staff see information relevant to their responsibilities; Admin has complete operational visibility. Proposed roles:'),
                ['type' => 'chips', 'items' => ['Admin', 'Sales', 'Designer', 'Production', 'QC', 'Dispatch', 'Accounts']],
                $p('The final role structure will be confirmed during implementation discovery.'),
            ]),

            $s('inventory', '19', 'Inventory Management', [
                $p('Phase 2 includes basic inventory management: materials, current stock, stock in, stock out, consumption, wastage, closing stock and low-stock alerts.'),
                $p('Procurement, supplier management, GRN, warehouse management and barcode systems are not included and can be considered as future enhancements.', 'muted'),
            ], true),

            $s('payment-invoicing', '20', 'Payment & Invoicing', [
                $p('Payments are processed through Razorpay.'),
                $ul([
                    'Online payment: UPI, cards and net banking where supported by Razorpay',
                    'Payment verification, advance payment and balance payment',
                    'Payment status, refund status where applicable, payment history',
                    'GST invoice generation',
                ]),
                $p('Razorpay transaction charges are billed by Razorpay directly and are separate from development cost.'),
            ]),

            $s('reports-analytics', '21', 'Reports & Analytics', [
                $grid([
                    ['Sales', 'Revenue', 'Orders', 'Customers'],
                    ['Products', 'Category performance', 'Staff performance', 'Department performance'],
                    ['Production performance', 'Delayed orders / jobs', 'Inventory', 'Payments'],
                    ['GST', 'Discounts', 'Coupons', 'Sales trends'],
                    ['Operational metrics', '', '', ''],
                ]),
                $p('Core operational reports are delivered in Phase 2; advanced reporting and analytics in Phase 3.'),
            ]),

            $s('admin-management', '22', 'Admin Management', [
                $p('Admin controls the storefront and operations from one panel: products, categories, pricing rules, banners and content, coupons and discounts, orders, customers, staff, roles, workflow stages, inventory and reports. Admin actions are recorded in the audit log.'),
            ], true),

            $s('performance-security', '23', 'Performance, Security & Scalability', [
                ['type' => 'table', 'header' => ['Performance', 'Security', 'Scalability & operations'], 'rows' => [
                    ['Responsive, mobile-first architecture', 'Secure authentication', 'Scalable architecture'],
                    ['Image optimisation and lazy loading', 'Role-based access control', 'Backup strategy'],
                    ['Caching', 'Secure file handling', 'Logging'],
                    ['Optimised database queries, API and code', 'Audit logs', 'Monitoring recommendations'],
                ]],
                $p('Specific performance scores are not promised unless separately agreed.'),
            ]),

            $s('mobile-readiness', '24', 'Future Mobile App Readiness', [
                $p('The backend and API will be designed with future Flutter apps in mind: a customer app, a staff app and an admin app.'),
                ['type' => 'callout', 'tone' => 'gray', 'text' => 'Mobile application development is outside the current scope and can be proposed as a separate phase in the future.'],
            ]),

            $s('timeline', '25', 'Development Timeline', [
                $p('Durations below are indicative estimates derived from the current scope.'),
                ['type' => 'table', 'header' => ['Phase', 'Stages', 'Indicative duration'], 'first_col_bold' => true, 'first_col_width' => 18, 'rows' => [
                    ['Phase 1', 'Discovery → UI/UX → Development → Testing → UAT → Deployment', '10–12 weeks'],
                    ['Phase 2', 'Planning → Development → Testing → UAT → Deployment', '8–10 weeks'],
                    ['Phase 3', 'Planning → Development → Optimization → Testing → Deployment', '4–6 weeks'],
                ]],
                ['type' => 'callout', 'tone' => 'brand', 'text' => 'Final timeline will be confirmed after detailed discovery, product configuration finalization and technical validation.'],
            ], true),

            $s('commercial-proposal', '26', 'Detailed Phase-wise Commercial Proposal', [
                $p('Feature-wise development costs are shown below. The overall initial project is positioned in the **Need to Be Finalised** range; line-item figures will be confirmed after discovery.'),
                $cost('Phase 1 — E-Commerce Foundation', [
                    ['E-commerce website, homepage, categories', '₹XX,XXX'],
                    ['Product catalogue, search, filtering, product detail', '₹XX,XXX'],
                    ['Product configuration', '₹XX,XXX'],
                    ['Pricing engine', '₹XX,XXX'],
                    ['Artwork upload & preflight', '₹XX,XXX'],
                    ['Cart, checkout & Razorpay payment', '₹XX,XXX'],
                    ['Customer account', '₹XX,XXX'],
                    ['Basic admin', '₹XX,XXX'],
                    ['Phase 1 subtotal · 10–12 weeks', '₹X,XX,XXX'],
                ]),
                $cost('Phase 2 — Business Operations', [
                    ['Order management (online + offline)', '₹XX,XXX'],
                    ['Quotation management', '₹XX,XXX'],
                    ['Job cards & production workflow', '₹XX,XXX'],
                    ['Staff, departments & role-based access', '₹XX,XXX'],
                    ['QC, packing & dispatch', '₹XX,XXX'],
                    ['Basic inventory', '₹XX,XXX'],
                    ['Payment tracking & GST invoices', '₹XX,XXX'],
                    ['Audit logs, dashboards & reports', '₹XX,XXX'],
                    ['Phase 2 subtotal · 8–10 weeks', '₹X,XX,XXX'],
                ]),
            ], true),

            $s('commercial-proposal-continued', '26', 'Commercial Proposal (continued)', [
                $cost('Phase 3 — Advanced Platform & Optimization', [
                    ['Advanced artwork preflight', '₹XX,XXX'],
                    ['Advanced analytics', '₹XX,XXX'],
                    ['Optimisation & platform hardening', '₹XX,XXX'],
                    ['Agreed enhancements', '₹XX,XXX'],
                    ['Phase 3 subtotal · 4–6 weeks', '₹XX,XXX'],
                ]),
                ['type' => 'total', 'label' => 'Total Development Investment', 'value' => '₹X,XX,XXX'],
                $p('GST as applicable. Excludes infrastructure and third-party costs (Section 27).', 'fine'),
            ], true),

            $s('infrastructure-costs', '27', 'Estimated Monthly Infrastructure & Third-Party Costs', [
                $p('These are operational costs paid to service providers, separate from development cost. All figures are estimated / to be finalized once hosting is selected.'),
                ['type' => 'table', 'header' => ['Category', 'Billing', 'Estimate'], 'last_col_right' => true, 'rows' => [
                    ['Hosting / server', 'Monthly', 'To be finalized'],
                    ['Database', 'Included with server or separate', 'To be finalized'],
                    ['Storage', 'Usage-based', 'To be finalized'],
                    ['CDN', 'Usage-based', 'To be finalized'],
                    ['Backup', 'Monthly', 'To be finalized'],
                    ['Monitoring', 'Monthly', 'To be finalized'],
                    ['Email', 'Usage-based', 'To be finalized'],
                    ['SMS / OTP', 'Per message', 'To be finalized'],
                    ['WhatsApp', 'Per message', 'To be finalized'],
                    ['Payment gateway (Razorpay)', 'Per transaction', 'As per Razorpay'],
                    ['Other third-party services', 'As applicable', 'To be finalized'],
                ]],
            ]),

            $s('optional-services', '28', 'Optional Services', [
                $p('Not included in the current scope; can be estimated separately on request. Third-party usage charges apply separately.'),
                $ul([
                    'Notification integrations: WhatsApp, SMS, email, push notifications',
                    'Flutter mobile apps (customer, staff, admin)',
                    'Courier API integration',
                    'ERP / accounting integration',
                    'Advanced inventory: procurement, suppliers, GRN, warehouse, barcode',
                ]),
            ], true),

            $s('maintenance-support', '29', 'Maintenance & Support', [
                $p('The first month after deployment includes a **free bug-fix and support period**. Maintenance after the first month will be discussed separately and may cover bug fixes, security updates, server management, monitoring and future enhancements.'),
                ['type' => 'table', 'header' => ['Type', 'Meaning', 'How it is handled'], 'first_col_bold' => true, 'rows' => [
                    ['Bug fix', 'Agreed functionality not working as specified', 'Free for the first month; then per maintenance terms'],
                    ['New feature', 'Functionality outside the agreed scope', 'Estimated separately'],
                    ['Third-party charges', 'Hosting, SMS, gateway and other service fees', 'Paid by Print Bazzar to providers'],
                ]],
            ]),

            $s('ownership-handover', '30', 'Ownership & Handover', [
                $p('After full payment, Print Bazzar receives ownership and control of:'),
                ['type' => 'cards', 'variant' => 'plain', 'columns' => 3, 'items' => array_map(fn ($t) => ['label' => '', 'text' => $t], [
                    'Source code', 'Database', 'Domain', 'Hosting account', 'Cloud accounts (where applicable)',
                    'API credentials', 'Storage', 'Git repository', 'Admin access',
                ])],
                $p('**No vendor lock-in.** Third-party services remain subject to their own terms and accounts.'),
            ], true),

            $s('assumptions', '31', 'Assumptions', [
                $ul([
                    'Print Bazzar will provide branding assets, product information, and product images, videos and content.',
                    'Print Bazzar will provide pricing and business rules, and GST/business information.',
                    'Print Bazzar will provide Razorpay and other required third-party credentials.',
                    'Product-specific configuration rules will be finalized during discovery.',
                    'Hosting infrastructure will be finalized before deployment.',
                    'Third-party service fees are separate.',
                    'Client approvals are required at agreed milestones.',
                ]),
            ]),

            $s('exclusions', '32', 'Exclusions', [
                $p('Unless separately agreed, the following are not included:'),
                $ul([
                    'Mobile app development',
                    'WhatsApp / SMS integrations',
                    'Advanced third-party integrations and advanced ERP/accounting integration',
                    'Advanced warehouse management',
                    'Custom courier API integrations',
                    'Features not listed in the agreed scope',
                    'Third-party subscription charges and payment gateway transaction fees',
                    'Major changes after approval of completed phases',
                ]),
            ], true),

            $s('payment-terms', '33', 'Payment Terms', [
                ['type' => 'cards', 'variant' => 'stat', 'columns' => 3, 'items' => [
                    ['label' => '50%', 'text' => 'Project initiation / advance'],
                    ['label' => '30%', 'text' => 'Mid-project / agreed milestone'],
                    ['label' => '20%', 'text' => 'Final delivery / production deployment'],
                ]],
            ]),

            $s('execution-process', '34', 'Project Execution Process', [
                ['type' => 'flow', 'tone' => 'light', 'numbered' => true, 'columns' => 5, 'steps' => [
                    'Requirement Validation', 'Technical & Product Discovery', 'Information Architecture', 'UI/UX', 'Development',
                    'Internal QA', 'Client UAT', 'Bug Fixes', 'Deployment', 'Post-Deployment Support',
                ]],
            ], true),

            $s('deliverables', '35', 'Deliverables', [
                $ul([
                    'Responsive e-commerce storefront and customer account (Phase 1)',
                    'Laravel admin and operations panel with role-based access (Phases 1–2)',
                    'Advanced platform capabilities as confirmed (Phase 3)',
                    'Deployed production environment on the finalized hosting',
                    'Source code in a Git repository owned by Print Bazzar',
                    'Database, credentials and admin access handover',
                    'Admin walkthrough and handover documentation',
                ]),
            ]),

            $s('next-steps', '36', 'Conclusion / Next Steps', [
                $p('This proposal sets out a phased path from a configurable print store to a complete business operations platform, with development and running costs kept separate and ownership transferred to Print Bazzar on full payment.'),
                ['type' => 'list', 'ordered' => true, 'boxed' => true, 'items' => [
                    'Review this proposal and share feedback or questions.',
                    'Confirm scope and commercials for Phase 1.',
                    'Sign-off and 50% advance to initiate the project.',
                    'Begin requirement validation and product discovery workshops.',
                ]],
                $p('Chakra App Studio · Proposal for Print Bazzar · September 2026', 'fine'),
            ]),
        ];
    }
}

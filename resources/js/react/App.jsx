import React, { useCallback, useEffect, useRef, useState } from 'react';
import { requestJson } from './api.js';
import { FuelCredit } from './pages/FuelCredit.jsx';
import WorkspacePage from './pages/WorkspacePages.jsx';

const products = [
    { name: 'Regular', color: 'bg-blue-100 text-blue-700', price: '₱62.40', stock: '8,420 L' },
    { name: 'Premium', color: 'bg-amber-100 text-amber-700', price: '₱66.80', stock: '5,120 L' },
    { name: 'Diesel', color: 'bg-emerald-100 text-emerald-700', price: '₱59.90', stock: '11,680 L' },
];

const transactions = [
    ['FC-10482', 'Regular', 'Manaoag', 'Oct 31, 2026 · 10:42 AM', '32.50 L', '₱2,028.00', 'Completed'],
    ['FC-10481', 'Diesel', 'San Fabian', 'Oct 31, 2026 · 10:35 AM', '45.00 L', '₱2,695.50', 'Completed'],
    ['FC-10480', 'Premium', 'Manaoag', 'Oct 31, 2026 · 10:18 AM', '28.75 L', '₱1,920.50', 'Completed'],
    ['FC-10479', 'Regular', 'San Fabian', 'Oct 31, 2026 · 9:56 AM', '20.00 L', '₱1,248.00', 'Completed'],
];

const inventory = [
    ['Regular', 'Tank 01', 'Manaoag', '4,820 L', '8,000 L', 60],
    ['Premium', 'Tank 02', 'Manaoag', '2,540 L', '5,000 L', 51],
    ['Diesel', 'Tank 03', 'Manaoag', '6,180 L', '10,000 L', 62],
    ['Regular', 'Tank 01', 'San Fabian', '3,600 L', '8,000 L', 45],
    ['Premium', 'Tank 02', 'San Fabian', '2,580 L', '5,000 L', 52],
    ['Diesel', 'Tank 03', 'San Fabian', '5,500 L', '10,000 L', 55],
];

const deliveries = [
    ['DLV-0082', 'Diesel · Tank 03', 'Manaoag', 'Oct 30, 2026', '5,000 L', 'Pending'],
    ['DLV-0081', 'Regular · Tank 01', 'San Fabian', 'Oct 29, 2026', '4,500 L', 'Approved'],
    ['DLV-0080', 'Premium · Tank 02', 'Manaoag', 'Oct 28, 2026', '3,000 L', 'Approved'],
];

const adjustments = [
    ['Tank 01 · Regular', 'M. Dela Cruz', 'Physical stock count variance', '-12.50 L', 'Oct 30, 2026', 'Pending'],
    ['Tank 03 · Diesel', 'J. Santos', 'Meter reconciliation', '+8.00 L', 'Oct 29, 2026', 'Approved'],
];

const team = [
    ['Maria Dela Cruz', 'maria@fivecegas.com', 'Manaoag', 'Staff', 'Active'],
    ['Jose Santos', 'jose@fivecegas.com', 'San Fabian', 'Staff', 'Active'],
    ['Lance Deyto', 'lance@fivecegas.com', 'All branches', 'Owner', 'Active'],
];

const salesByDay = [
    ['Mon', 42], ['Tue', 58], ['Wed', 48], ['Thu', 72], ['Fri', 64], ['Sat', 88], ['Sun', 68],
];

const pageTitles = {
    dashboard: 'Dashboard',
    inventory: 'Fuel inventory',
    sales: 'Fuel sales',
    transactions: 'Transactions',
    deliveries: 'Stock-in',
    adjustments: 'Stock adjustments',
    prices: 'Fuel prices',
    reports: 'Sales reports',
    credit: 'Fuel credit',
    setup: 'Station setup',
    users: 'Team management',
};

const navigation = [
    { label: 'Dashboard', href: '/', icon: '⌂', page: 'dashboard' },
    { label: 'Fuel inventory', href: '/inventory', icon: '▤', page: 'inventory' },
    { label: 'Stock-in', href: '/deliveries', icon: '↓', page: 'deliveries' },
    { label: 'Fuel sales', href: '/sales', icon: '↗', page: 'sales' },
    { label: 'Transactions', href: '/transactions', icon: '▦', page: 'transactions' },
    { label: 'Sales reports', href: '/reports', icon: '▥', page: 'reports' },
    { label: 'Fuel prices', href: '/fuel-prices', icon: '₱', page: 'prices' },
    { label: 'Fuel credit', href: '/credit', icon: '◷', page: 'credit' },
    { label: 'Stock adjustments', href: '/adjustments', icon: '↔', page: 'adjustments' },
    { label: 'Station setup', href: '/setup', icon: '⚙', page: 'setup' },
    { label: 'Team management', href: '/users', icon: '♙', page: 'users' },
];

function Button({ children, className = '', type = 'button', ...props }) {
    return (
        <button
            type={type}
            className={`primary-gradient inline-flex min-h-10 items-center justify-center gap-2 rounded-xl px-4 py-2.5 text-sm font-bold text-white shadow-md shadow-rose-950/15 transition hover:-translate-y-0.5 hover:shadow-lg focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-rose-800 disabled:cursor-not-allowed disabled:opacity-60 ${className}`}
            {...props}
        >
            {children}
        </button>
    );
}

function PageHeading({ eyebrow, title, description, action }) {
    return (
        <div className="mb-6 flex flex-wrap items-end justify-between gap-4">
            <div>
                <p className="mb-1 text-[11px] font-extrabold uppercase tracking-[0.18em] text-rose-700">{eyebrow}</p>
                <h1 className="text-2xl font-extrabold tracking-tight text-slate-900 sm:text-3xl">{title}</h1>
                <p className="mt-1.5 max-w-2xl text-sm text-slate-500">{description}</p>
            </div>
            {action}
        </div>
    );
}

function Panel({ title, subtitle, action, children, className = '', id, allowOverflow = false }) {
    return (
        <section className={`min-w-0 ${allowOverflow ? 'overflow-visible' : 'overflow-hidden'} rounded-2xl border border-slate-200 bg-white shadow-sm shadow-slate-900/[0.03] ${className}`} id={id}>
            {(title || action) && (
                <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-4">
                    <div>
                        {title && <h2 className="font-bold text-slate-900">{title}</h2>}
                        {subtitle && <p className="mt-1 text-xs text-slate-500">{subtitle}</p>}
                    </div>
                    {action}
                </div>
            )}
            {children}
        </section>
    );
}

function MetricCard({ label, value, change, icon, tone = 'rose' }) {
    const tones = {
        rose: 'bg-rose-50 text-rose-700',
        blue: 'bg-blue-50 text-blue-700',
        emerald: 'bg-emerald-50 text-emerald-700',
        amber: 'bg-amber-50 text-amber-700',
    };
    return (
        <article className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm shadow-slate-900/[0.03]">
            <div className="flex items-start justify-between gap-4">
                <div>
                    <p className="text-sm font-medium text-slate-500">{label}</p>
                    <p className="mt-2 text-2xl font-extrabold tracking-tight text-slate-900">{value}</p>
                    <p className="mt-2 text-xs font-semibold text-emerald-700">{change}</p>
                </div>
                <span className={`grid size-11 place-items-center rounded-xl text-lg font-bold ${tones[tone]}`}>{icon}</span>
            </div>
        </article>
    );
}

function Badge({ children, tone = 'green' }) {
    const tones = {
        green: 'bg-emerald-50 text-emerald-700 ring-emerald-600/15',
        amber: 'bg-amber-50 text-amber-700 ring-amber-600/15',
        blue: 'bg-blue-50 text-blue-700 ring-blue-600/15',
        red: 'bg-rose-50 text-rose-700 ring-rose-600/15',
        slate: 'bg-slate-100 text-slate-600 ring-slate-500/15',
    };
    return <span className={`inline-flex rounded-full px-2.5 py-1 text-xs font-bold ring-1 ring-inset ${tones[tone]}`}>{children}</span>;
}

function DataTable({ headers, children }) {
    return (
        <div className="overflow-x-auto">
            <table className="w-full min-w-[720px] text-left text-sm">
                <thead className="bg-slate-50 text-[10px] font-extrabold uppercase tracking-wider text-slate-500">
                    <tr>{headers.map((header) => <th className="px-5 py-3.5" key={header}>{header}</th>)}</tr>
                </thead>
                <tbody className="divide-y divide-slate-100 text-slate-700">{children}</tbody>
            </table>
        </div>
    );
}

function Field({ label, children, className = '' }) {
    return (
        <label className={`grid min-w-0 gap-1.5 text-sm font-semibold text-slate-700 ${className}`}>
            {label}
            {children}
        </label>
    );
}

function Input({ className = '', ...props }) {
    return (
        <input
            className={`min-h-11 min-w-0 w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm font-medium text-slate-800 outline-none transition placeholder:font-normal placeholder:text-slate-400 focus:border-rose-400 focus:ring-4 focus:ring-rose-100 ${className}`}
            {...props}
        />
    );
}

function Select({ children, ...props }) {
    return (
        <select
            className="min-h-11 min-w-0 w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm font-medium text-slate-800 outline-none transition focus:border-rose-400 focus:ring-4 focus:ring-rose-100"
            {...props}
        >
            {children}
        </select>
    );
}

function DemoForm({ children, onSubmit, className = '' }) {
    return <form className={`grid gap-4 ${className}`} onSubmit={onSubmit}>{children}</form>;
}

function ServerForm({ action, method = 'POST', children, className = '' }) {
    return (
        <form action={action} className={`grid gap-4 ${className}`} method="POST">
            <input name="_token" type="hidden" value={document.querySelector('meta[name="csrf-token"]')?.content ?? ''} />
            {method !== 'POST' && <input name="_method" type="hidden" value={method} />}
            {children}
        </form>
    );
}

function DashboardPage({ userName }) {
    return (
        <>
            <PageHeading
                eyebrow="Five CE Gas Station"
                title={`Good morning, ${userName.split(' ')[0]}`}
                description="Here’s what’s happening across your stations today."
                action={<Button><span aria-hidden="true">＋</span> New transaction</Button>}
            />
            <div className="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <MetricCard label="Today’s sales" value="₱48,620" change="↑ 12.8% from yesterday" icon="₱" tone="rose" />
                <MetricCard label="Fuel dispensed" value="812.5 L" change="Across 2 branches" icon="◉" tone="blue" />
                <MetricCard label="Inventory on hand" value="32,200 L" change="68% total capacity" icon="▤" tone="emerald" />
                <MetricCard label="Pending approvals" value="3 items" change="Needs your review" icon="!" tone="amber" />
            </div>
            <div className="grid gap-5 xl:grid-cols-[1.4fr_1fr]">
                <Panel title="Weekly sales overview" subtitle="Sales revenue across all branches">
                    <div className="flex items-center justify-between px-5 pt-4 text-xs text-slate-400">
                        <span>₱60k</span><span>This week</span>
                    </div>
                    <div className="flex h-56 items-end justify-around gap-3 px-5 pb-3 pt-2">
                        {salesByDay.map(([day, value]) => (
                            <div className="flex h-full flex-1 flex-col items-center justify-end gap-2" key={day}>
                                <span className="text-[10px] font-bold text-slate-500">₱{Math.round(value * 620)}</span>
                                <div className="flex h-40 w-full items-end rounded-t-lg bg-slate-50">
                                    <div className="w-full rounded-t-lg bg-gradient-to-t from-rose-700 to-rose-400" style={{ height: `${value}%` }} />
                                </div>
                                <span className="text-xs font-semibold text-slate-500">{day}</span>
                            </div>
                        ))}
                    </div>
                    <div className="flex items-center gap-2 border-t border-slate-100 px-5 py-3 text-xs text-slate-500"><span className="size-2 rounded-full bg-rose-600" /> Daily sales</div>
                </Panel>
                <Panel title="Inventory by fuel" subtitle="Current stock across both branches" id="fuel-inventory" action={<a className="text-xs font-bold text-rose-700 hover:text-rose-900" href="/inventory">View inventory</a>}>
                    <div className="grid gap-4 p-5">
                        {products.map((product) => (
                            <div key={product.name}>
                                <div className="mb-2 flex items-center justify-between gap-3">
                                    <div className="flex items-center gap-2"><span className={`size-2.5 rounded-full ${product.color.split(' ')[0]}`} /><span className="text-sm font-bold text-slate-800">{product.name}</span></div>
                                    <span className="text-sm font-extrabold text-slate-800">{product.stock}</span>
                                </div>
                                <div className="h-2 overflow-hidden rounded-full bg-slate-100"><div className="h-full rounded-full bg-gradient-to-r from-rose-600 to-blue-700" style={{ width: `${Number.parseInt(product.stock.replace(',', ''), 10) / 400}%` }} /></div>
                            </div>
                        ))}
                    </div>
                    <div className="border-t border-slate-100 px-5 py-3 text-xs text-slate-500">Stock levels refresh as sales and deliveries are recorded.</div>
                </Panel>
            </div>
            <Panel title="Recent transactions" subtitle="The latest recorded sales" className="mt-5" action={<a className="text-xs font-bold text-rose-700 hover:text-rose-900" href="/transactions">View all</a>}>
                <DataTable headers={['Receipt', 'Fuel type', 'Branch', 'Date & time', 'Quantity', 'Amount', 'Status']}>
                    {transactions.slice(0, 3).map((row) => (
                        <tr className="hover:bg-slate-50/80" key={row[0]}>
                            {row.map((cell, index) => <td className="whitespace-nowrap px-5 py-4" key={`${row[0]}-${index}`}>{index === 6 ? <Badge>{cell}</Badge> : index === 5 ? <strong className="text-slate-900">{cell}</strong> : cell}</td>)}
                        </tr>
                    ))}
                </DataTable>
            </Panel>
        </>
    );
}

function InventoryPage() {
    return (
        <>
            <PageHeading eyebrow="Stock monitoring" title="Fuel inventory" description="Review current stock levels and tank capacity by branch." action={<Button>＋ Record stock-in</Button>} />
            <div className="mb-5 grid gap-4 sm:grid-cols-3">
                {products.map((product) => <MetricCard key={product.name} label={`${product.name} · combined stock`} value={product.stock} change={`Current price ${product.price}/L`} icon="◉" />)}
            </div>
            <Panel title="Tank levels" subtitle="On-hand fuel, measured against tank capacity">
                <DataTable headers={['Fuel type', 'Tank', 'Branch', 'Current stock', 'Capacity', 'Level', 'Status']}>
                    {inventory.map(([fuel, tank, branch, stock, capacity, level]) => (
                        <tr key={`${branch}-${tank}`}>
                            <td className="px-5 py-4 font-bold text-slate-900">{fuel}</td><td className="px-5 py-4">{tank}</td><td className="px-5 py-4">{branch}</td><td className="px-5 py-4 font-semibold">{stock}</td><td className="px-5 py-4">{capacity}</td>
                            <td className="px-5 py-4"><div className="flex items-center gap-2"><div className="h-2 w-24 rounded-full bg-slate-100"><div className="h-2 rounded-full bg-gradient-to-r from-rose-600 to-blue-700" style={{ width: `${level}%` }} /></div><span className="text-xs text-slate-500">{level}%</span></div></td>
                            <td className="px-5 py-4"><Badge tone={level < 25 ? 'amber' : 'green'}>{level < 25 ? 'Low stock' : 'In stock'}</Badge></td>
                        </tr>
                    ))}
                </DataTable>
            </Panel>
        </>
    );
}

function TransactionsPage() {
    const [query, setQuery] = useState('');
    const filtered = transactions.filter((row) => row.join(' ').toLowerCase().includes(query.toLowerCase()));
    return (
        <>
            <PageHeading eyebrow="Activity" title="Transactions" description="Search and review fuel sales recorded at your stations." action={<Button>Export transactions</Button>} />
            <Panel title="Sales activity" subtitle={`${filtered.length} transactions shown`} action={<Input aria-label="Search transactions" className="min-h-9 w-56" placeholder="Search receipt or fuel…" value={query} onChange={(event) => setQuery(event.target.value)} />}>
                <DataTable headers={['Receipt', 'Fuel type', 'Branch', 'Date & time', 'Quantity', 'Amount', 'Status']}>
                    {filtered.map((row) => (
                        <tr key={row[0]}>{row.map((cell, index) => <td className="whitespace-nowrap px-5 py-4" key={`${row[0]}-${index}`}>{index === 6 ? <Badge>{cell}</Badge> : index === 5 ? <strong className="text-slate-900">{cell}</strong> : cell}</td>)}</tr>
                    ))}
                </DataTable>
            </Panel>
        </>
    );
}

function SalesPage({ onSubmit }) {
    const [fuel, setFuel] = useState('Regular');
    return (
        <>
            <PageHeading eyebrow="Point of sale" title="Record fuel sale" description="Choose a product and enter the volume dispensed to preview a transaction." action={<Badge tone="blue">Manaoag branch</Badge>} />
            <div className="grid gap-5 xl:grid-cols-[1.2fr_.8fr]">
                <Panel title="New sale" subtitle="Transaction details">
                    <DemoForm className="p-5" onSubmit={onSubmit}>
                        <fieldset>
                            <legend className="mb-3 text-sm font-bold text-slate-800">Select fuel type</legend>
                            <div className="grid gap-3 sm:grid-cols-3">
                                {products.map((product) => (
                                    <button key={product.name} type="button" onClick={() => setFuel(product.name)} className={`rounded-xl border p-4 text-left transition ${fuel === product.name ? 'primary-gradient border-rose-900 text-white ring-2 ring-rose-100' : 'border-slate-200 bg-slate-50 text-slate-800 hover:border-rose-300'}`}>
                                        <span className={`mb-3 grid size-9 place-items-center rounded-xl text-lg ${product.color}`}>◉</span>
                                        <span className={`block font-bold ${fuel === product.name ? 'text-white' : 'text-slate-900'}`}>{product.name}</span>
                                        <span className={`mt-1 block text-xs ${fuel === product.name ? 'text-white/75' : 'text-slate-500'}`}>{product.price} / liter</span>
                                    </button>
                                ))}
                            </div>
                        </fieldset>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="Pump / nozzle"><Select defaultValue=""><option value="" disabled>Choose pump</option><option>Pump 01 · Nozzle A</option><option>Pump 02 · Nozzle B</option></Select></Field>
                            <Field label="Liters dispensed"><Input required type="number" min="0.001" step="0.001" placeholder="0.000" /></Field>
                        </div>
                        <Field label="Receipt number"><Input placeholder="Optional" /></Field>
                        <div className="flex flex-wrap items-center justify-between gap-4 rounded-xl bg-slate-50 p-4">
                            <span className="text-sm font-semibold text-slate-500">Estimated total</span><strong className="text-2xl text-slate-900">{products.find((item) => item.name === fuel)?.price} <span className="text-sm font-medium text-slate-500">per liter</span></strong>
                        </div>
                        <Button type="submit" className="w-full">Complete sale <span aria-hidden="true">→</span></Button>
                    </DemoForm>
                </Panel>
                <Panel title="Branch snapshot" subtitle="Current operating status">
                    <div className="grid gap-4 p-5">
                        <div className="rounded-xl bg-gradient-to-r from-[#172541] to-[#5b243e] p-5 text-white"><p className="text-xs font-semibold uppercase tracking-widest text-white/70">Today’s sales</p><p className="mt-2 text-3xl font-extrabold">₱24,860.00</p><p className="mt-1 text-xs text-white/70">46 transactions recorded</p></div>
                        {products.map((product) => <div className="flex items-center justify-between rounded-xl border border-slate-100 p-4" key={product.name}><span className="font-semibold text-slate-700">{product.name} price</span><strong className="text-slate-900">{product.price}/L</strong></div>)}
                    </div>
                </Panel>
            </div>
            <Panel title="Recent sales" subtitle="Latest transactions for this branch" className="mt-5">
                <DataTable headers={['Receipt', 'Fuel', 'Branch', 'Date & time', 'Quantity', 'Amount', 'Status']}>
                    {transactions.slice(0, 3).map((row) => <tr key={row[0]}>{row.map((cell, index) => <td className="whitespace-nowrap px-5 py-4" key={`${row[0]}-${index}`}>{index === 6 ? <Badge>{cell}</Badge> : cell}</td>)}</tr>)}
                </DataTable>
            </Panel>
        </>
    );
}

function DeliveriesPage({ onSubmit }) {
    return (
        <>
            <PageHeading eyebrow="Stock-in" title="Fuel deliveries" description="Record received fuel and keep a clear review trail." action={<Button>＋ New delivery</Button>} />
            <div className="mb-5 grid gap-5 xl:grid-cols-[1.2fr_.8fr]">
                <Panel title="Record a delivery" subtitle="Delivery details">
                    <DemoForm className="p-5" onSubmit={onSubmit}>
                        <Field label="Receiving tank"><Select defaultValue=""><option value="" disabled>Select a tank</option><option>Tank 01 · Regular · Manaoag</option><option>Tank 02 · Premium · Manaoag</option><option>Tank 03 · Diesel · San Fabian</option></Select></Field>
                        <div className="grid gap-4 sm:grid-cols-2"><Field label="Quantity delivered (L)"><Input type="number" placeholder="0.000" /></Field><Field label="Cost per liter (₱)"><Input type="number" placeholder="0.00" /></Field></div>
                        <div className="grid gap-4 sm:grid-cols-2"><Field label="Supplier"><Input placeholder="Supplier name" /></Field><Field label="Reference number"><Input placeholder="Invoice or delivery no." /></Field></div>
                        <Field label="Received at"><Input type="datetime-local" /></Field>
                        <p className="rounded-xl bg-amber-50 px-4 py-3 text-xs font-medium text-amber-800">Stock updates after the delivery is reviewed and approved.</p>
                        <Button type="submit">Submit delivery for review <span aria-hidden="true">→</span></Button>
                    </DemoForm>
                </Panel>
                <Panel title="Approval workflow" subtitle="Stock is only updated after review">
                    <div className="grid gap-3 p-5">
                        {['Record delivered quantity and supplier', 'Owner reviews delivery details', 'Approved volume updates the stock ledger'].map((step, index) => <div className="flex items-center gap-3 rounded-xl bg-slate-50 p-3" key={step}><span className="grid size-8 shrink-0 place-items-center rounded-full bg-white text-sm font-extrabold text-rose-700 shadow-sm">{index + 1}</span><span className="text-sm font-medium text-slate-600">{step}</span></div>)}
                    </div>
                </Panel>
            </div>
            <Panel title="Delivery records" subtitle="Recent stock-in activity">
                <DataTable headers={['Reference', 'Tank / fuel', 'Branch', 'Received', 'Quantity', 'Status', 'Action']}>
                    {deliveries.map((row) => <tr key={row[0]}>{row.map((cell, index) => <td className="whitespace-nowrap px-5 py-4" key={`${row[0]}-${index}`}>{index === 5 ? <Badge tone={cell === 'Pending' ? 'amber' : 'green'}>{cell}</Badge> : cell}</td>)}<td className="px-5 py-4"><Button className="min-h-8 rounded-lg px-3 py-1 text-xs">Review</Button></td></tr>)}
                </DataTable>
            </Panel>
        </>
    );
}

function AdjustmentsPage({ onSubmit }) {
    return (
        <>
            <PageHeading eyebrow="Variance control" title="Stock adjustments" description="Log a discrepancy and track its review before stock is corrected." />
            <div className="mb-5 grid gap-5 xl:grid-cols-[1.2fr_.8fr]">
                <Panel title="Report a discrepancy" subtitle="Describe and submit the stock correction request">
                    <DemoForm className="p-5" onSubmit={onSubmit}>
                        <Field label="Tank"><Select defaultValue=""><option value="" disabled>Select a tank</option><option>Tank 01 · Regular · Manaoag</option><option>Tank 03 · Diesel · San Fabian</option></Select></Field>
                        <Field label="Stock change (liters)"><Input type="number" step="0.001" placeholder="Use a minus sign for stock loss" /></Field>
                        <Field label="Reason for adjustment"><textarea className="min-h-28 rounded-xl border border-slate-200 px-3.5 py-3 text-sm outline-none focus:border-rose-400 focus:ring-4 focus:ring-rose-100" placeholder="Describe the discrepancy and how it was checked." /></Field>
                        <p className="rounded-xl bg-blue-50 px-4 py-3 text-xs font-medium text-blue-800">Requests do not change stock until approved.</p>
                        <Button type="submit">Submit for review <span aria-hidden="true">→</span></Button>
                    </DemoForm>
                </Panel>
                <Panel title="Auditable corrections" subtitle="Original movement records remain unchanged">
                    <div className="grid gap-3 p-5">{['Reason attached to every request', 'Owner approval tracked with reviewer', 'Stock movement history remains traceable'].map((text) => <div className="flex items-center gap-3 rounded-xl bg-slate-50 p-3 text-sm text-slate-600" key={text}><span className="grid size-7 place-items-center rounded-full bg-emerald-100 font-bold text-emerald-700">✓</span>{text}</div>)}</div>
                </Panel>
            </div>
            <Panel title="Adjustment requests" subtitle="Recent stock discrepancies">
                <DataTable headers={['Tank', 'Reported by', 'Reason', 'Change', 'Requested', 'Status']}>
                    {adjustments.map((row) => <tr key={row[1]}>{row.map((cell, index) => <td className="px-5 py-4" key={`${row[1]}-${index}`}>{index === 3 ? <strong className={cell.startsWith('-') ? 'text-rose-700' : 'text-emerald-700'}>{cell}</strong> : index === 5 ? <Badge tone={cell === 'Pending' ? 'amber' : 'green'}>{cell}</Badge> : cell}</td>)}</tr>)}
                </DataTable>
            </Panel>
        </>
    );
}

function PricesPage() {
    return (
        <>
            <PageHeading eyebrow="Branch pricing" title="Fuel prices" description="Manage the prices currently used when recording a sale." action={<Button>＋ Update prices</Button>} />
            <div className="mb-5 grid gap-4 sm:grid-cols-3">{products.map((product) => <article className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm" key={product.name}><p className="text-sm font-semibold text-slate-500">{product.name}</p><p className="mt-2 text-3xl font-extrabold text-slate-900">{product.price}<span className="ml-1 text-sm font-semibold text-slate-400">/L</span></p><p className="mt-2 text-xs text-slate-500">Effective Oct 28, 2026</p></article>)}</div>
            <Panel title="Current prices" subtitle="Price history is retained for past sales" action={<Button>Manage pricing</Button>}>
                <DataTable headers={['Branch', 'Fuel type', 'Price per liter', 'Effective from', 'Status']}>
                    {['Manaoag', 'San Fabian'].flatMap((branch) => products.map((product) => <tr key={`${branch}-${product.name}`}><td className="px-5 py-4 font-semibold text-slate-900">{branch}</td><td className="px-5 py-4">{product.name}</td><td className="px-5 py-4 font-extrabold">{product.price}</td><td className="px-5 py-4">Oct 28, 2026 · 8:00 AM</td><td className="px-5 py-4"><Badge>Active</Badge></td></tr>))}
                </DataTable>
            </Panel>
        </>
    );
}

function ReportsPage() {
    return (
        <>
            <PageHeading eyebrow="Performance" title="Sales reports" description="Compare station performance and review fuel volume trends." action={<Button>Download report</Button>} />
            <div className="mb-5 flex flex-wrap gap-2">{['Last 7 days', 'This month', 'All branches'].map((option, index) => <button className={`rounded-xl border px-4 py-2 text-sm font-bold ${index === 0 ? 'primary-gradient border-rose-900 text-white' : 'border-slate-200 bg-white text-slate-600'}`} key={option} type="button">{option}</button>)}</div>
            <div className="mb-5 grid gap-4 sm:grid-cols-3"><MetricCard label="Gross sales" value="₱328,450" change="↑ 8.4% vs prior period" icon="₱" /><MetricCard label="Liters sold" value="5,842 L" change="Across all fuel types" icon="◉" tone="blue" /><MetricCard label="Transactions" value="1,284" change="Average ₱255.80 / sale" icon="▦" tone="emerald" /></div>
            <Panel title="Sales by day" subtitle="Revenue and volume trends for this week">
                <div className="flex h-72 items-end justify-around gap-4 px-5 pb-5 pt-8">{salesByDay.map(([day, value]) => <div className="flex h-full flex-1 flex-col items-center justify-end gap-2" key={day}><div className="flex h-56 w-full items-end justify-center gap-1.5"><div className="w-1/3 rounded-t-md bg-gradient-to-t from-rose-700 to-rose-400" style={{ height: `${value}%` }} /><div className="w-1/3 rounded-t-md bg-gradient-to-t from-blue-800 to-blue-500" style={{ height: `${Math.min(96, value + 8)}%` }} /></div><span className="text-xs font-semibold text-slate-500">{day}</span></div>)}</div>
                <div className="flex gap-5 border-t border-slate-100 px-5 py-3 text-xs text-slate-500"><span className="flex items-center gap-2"><i className="size-2 rounded-full bg-rose-600" />Manaoag</span><span className="flex items-center gap-2"><i className="size-2 rounded-full bg-blue-700" />San Fabian</span></div>
            </Panel>
            <Panel title="Sales by fuel type" subtitle="Current reporting period" className="mt-5">
                <DataTable headers={['Fuel type', 'Volume sold', 'Revenue', 'Share']}>{products.map((product, index) => <tr key={product.name}><td className="px-5 py-4 font-bold text-slate-900">{product.name}</td><td className="px-5 py-4">{['2,840 L', '1,205 L', '1,797 L'][index]}</td><td className="px-5 py-4 font-semibold">{['₱176,816', '₱80,494', '₱107,641'][index]}</td><td className="px-5 py-4">{['54%', '25%', '21%'][index]}</td></tr>)}</DataTable>
            </Panel>
        </>
    );
}

function SetupEditModal({ title, onClose, children }) {
    useEffect(() => {
        const closeOnEscape = (event) => {
            if (event.key === 'Escape') onClose();
        };
        window.addEventListener('keydown', closeOnEscape);
        return () => window.removeEventListener('keydown', closeOnEscape);
    }, [onClose]);

    return (
        <div className="fixed inset-0 z-[100] grid place-items-center overflow-y-auto bg-slate-950/50 p-4" onMouseDown={(event) => {
            if (event.target === event.currentTarget) onClose();
        }}>
            <section aria-labelledby="setup-edit-modal-title" aria-modal="true" className="my-auto max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-2xl bg-white shadow-2xl" role="dialog">
                <header className="sticky top-0 z-10 flex items-center justify-between gap-4 border-b border-slate-100 bg-white px-5 py-4">
                    <h2 className="text-lg font-bold text-slate-900" id="setup-edit-modal-title">{title}</h2>
                    <button aria-label="Close edit dialog" className="grid size-9 place-items-center rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50" onClick={onClose} type="button">
                        <svg aria-hidden="true" className="size-4" fill="none" stroke="currentColor" strokeLinecap="round" strokeWidth="2" viewBox="0 0 24 24"><path d="m6 6 12 12M18 6 6 18" /></svg>
                    </button>
                </header>
                <div className="p-5">{children}</div>
            </section>
        </div>
    );
}

function SetupActionIcon({ kind }) {
    const paths = {
        edit: <path d="m15 5 4 4M4 20l4-.8L19 8a2.1 2.1 0 0 0-3-3L5 16l-1 4Z" />,
        save: <path d="m5 12 4 4L19 6" />,
        archive: <><path d="M3 7h18M5 7l1 14h12l1-14" /><path d="M9 7V4h6v3m-5 4v6m4-6v6" /></>,
        activate: <><path d="M12 5v14M5 12h14" /></>,
    };

    return <svg aria-hidden="true" fill="none" height="20" stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="2.2" style={{ display: 'block', flex: 'none', height: 20, width: 20 }} viewBox="0 0 24 24" width="20">{paths[kind]}</svg>;
}

function SetupIconButton({ kind, label, className = '', ...props }) {
    const colors = {
        edit: 'border-violet-300 bg-white text-violet-700 hover:bg-violet-50',
        save: 'border-emerald-700 bg-gradient-to-r from-emerald-600 to-emerald-800 text-white hover:from-emerald-700 hover:to-emerald-900',
        archive: 'border-rose-800 bg-gradient-to-r from-rose-600 to-rose-900 text-white hover:from-rose-700 hover:to-rose-950',
        activate: 'border-emerald-700 bg-gradient-to-r from-emerald-600 to-emerald-800 text-white hover:from-emerald-700 hover:to-emerald-900',
    };

    return <button aria-label={label} className={`grid size-10 shrink-0 place-items-center rounded-lg border transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-500 ${colors[kind]} ${className}`} title={label} type="button" {...props}><SetupActionIcon kind={kind} /></button>;
}

function SetupPage({ data, activeBranchId, status, error }) {
    const [editingTankId, setEditingTankId] = useState(null);
    const formatLiters = (value) => `${new Intl.NumberFormat('en-PH', { maximumFractionDigits: 3 }).format(value)} L`;
    const selectedBranchId = data.activeBranchId || activeBranchId;
    const activeBranch = data.branches.find((branch) => String(branch.id) === String(selectedBranchId));
    const activeProducts = data.products.filter((product) => product.isActive);
    const editingTank = data.tanks.find((tank) => tank.id === editingTankId);

    return (
        <>
            <PageHeading eyebrow="Station configuration" title="Station setup" description="Manage measured tank stock and review each physical pump's fuel connections." />
            {status && <div className="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800" role="status">{status}</div>}
            {error && <div className="mb-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-medium text-rose-800" role="alert">{error}</div>}
            <div className="mb-5 grid gap-5 xl:grid-cols-[.8fr_1.2fr]">
                <Panel id="register-tank" title="Register a tank" subtitle="New tanks begin with the measured opening stock you enter.">
                    <ServerForm action="/setup/tanks" className="p-5">
                        <input name="branch_id" type="hidden" value={selectedBranchId || ''} />
                        <Field label="Branch"><p className="flex min-h-10 items-center rounded-xl bg-slate-50 px-3.5 py-3 text-sm font-semibold text-slate-700">{activeBranch?.name || 'Select an active branch from the sidebar'}</p></Field>
                        <Field label="Tank code"><Input autoComplete="off" name="code" placeholder="Example: B1-P4-T01" required /></Field>
                        <Field label="Fuel type">
                            <Select name="fuel_product_id" required>
                                {activeProducts.map((product) => <option key={product.id} value={product.id}>{product.name}</option>)}
                            </Select>
                        </Field>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <Field label="Capacity (liters)"><Input min="0.001" name="capacity_liters" required step="0.001" type="number" /></Field>
                            <Field label="Measured opening stock (liters)"><Input defaultValue="0" min="0" name="opening_stock_liters" required step="0.001" type="number" /></Field>
                        </div>
                        <p className="text-xs leading-5 text-slate-500">The opening measurement is recorded in the permanent inventory ledger. You can enter 0 and reconcile later.</p>
                        <Button className="bg-emerald-700 hover:bg-emerald-800 focus-visible:outline-emerald-700" type="submit">
                            <svg aria-hidden="true" className="size-4" fill="none" stroke="currentColor" strokeLinecap="round" strokeWidth="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14" /></svg>
                            Add tank
                        </Button>
                    </ServerForm>
                </Panel>
                <Panel allowOverflow title="Pump and nozzle map" subtitle="Physical pump labels are branch-specific; internal codes stay unique for the POS.">
                    <div className="grid gap-3 p-5 sm:grid-cols-2">
                        {data.pumps.map((pump) => (
                            <article className="rounded-xl border border-slate-200 p-4" key={pump.id}>
                                <div className="flex items-start justify-between gap-3">
                                    <div>
                                        <p className="font-bold text-slate-900">{pump.label || 'Unlabeled pump'}</p>
                                        <p className="mt-1 text-xs font-medium text-slate-500">{pump.branchName} · internal ID {pump.code}</p>
                                    </div>
                                    <Badge tone={pump.isActive ? 'green' : 'slate'}>{pump.isActive ? 'Active' : 'Archived'}</Badge>
                                </div>
                                <details className="mt-3">
                                    <summary aria-label={`Edit pump ${pump.label || pump.code}`} className="grid size-10 cursor-pointer list-none place-items-center rounded-lg border border-violet-300 bg-white text-violet-700 transition hover:bg-violet-50 [&::-webkit-details-marker]:hidden" title="Edit pump"><SetupActionIcon kind="edit" /></summary>
                                    <ServerForm action={`/setup/pumps/${pump.id}`} className="mt-3 grid gap-2" method="PUT">
                                        <Field label="Internal code"><Input defaultValue={pump.code} name="code" required /></Field>
                                        <Field label="Physical label"><Input defaultValue={pump.label || ''} name="label" required /></Field>
                                        <SetupIconButton kind="save" label={`Save pump ${pump.label || pump.code}`} type="submit" />
                                    </ServerForm>
                                </details>
                                <ServerForm action={`/setup/pumps/${pump.id}/status`} className="mt-2" method="PATCH">
                                    <input name="is_active" type="hidden" value={pump.isActive ? '0' : '1'} />
                                    <SetupIconButton kind={pump.isActive ? 'archive' : 'activate'} label={pump.isActive ? `Archive pump ${pump.label || pump.code}` : `Reactivate pump ${pump.label || pump.code}`} type="submit" />
                                </ServerForm>
                                <div className="mt-4 grid gap-2">
                                    {pump.nozzles.map((nozzle) => (
                                        <div className="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-slate-50 px-3 py-2" key={nozzle.id}>
                                            <span className="text-xs font-semibold text-slate-700">{nozzle.productName} · {nozzle.code} · {nozzle.tankCode}</span>
                                            <div className="flex items-center gap-2">
                                                <details className="relative open:z-50">
                                                    <summary aria-label={`Edit nozzle ${nozzle.code}`} className="grid size-10 cursor-pointer list-none place-items-center rounded-lg border border-violet-300 bg-white text-violet-700 transition hover:bg-violet-50 [&::-webkit-details-marker]:hidden" title="Edit nozzle"><SetupActionIcon kind="edit" /></summary>
                                                    <ServerForm action={`/setup/nozzles/${nozzle.id}`} className="absolute right-0 z-[100] mt-2 grid w-64 gap-2 rounded-xl border border-slate-200 bg-white p-3 shadow-xl" method="PUT">
                                                        <Field label="Nozzle code"><Input defaultValue={nozzle.code} name="code" required /></Field>
                                                        <Field label="Pump"><Select defaultValue={pump.id} name="fuel_pump_id" required>{data.pumps.filter((item) => item.branchId === pump.branchId && item.isActive).map((item) => <option key={item.id} value={item.id}>{item.label} · {item.code}</option>)}</Select></Field>
                                                        <Field label="Tank"><Select defaultValue={nozzle.tankId} name="fuel_tank_id" required>{data.tanks.filter((tank) => tank.branchId === pump.branchId && tank.isActive).map((tank) => <option key={tank.id} value={tank.id}>{tank.code} · {tank.productName}</option>)}</Select></Field>
                                                        <SetupIconButton kind="save" label={`Save nozzle ${nozzle.code}`} type="submit" />
                                                    </ServerForm>
                                                </details>
                                                <Badge tone={nozzle.isActive ? 'green' : 'slate'}>{nozzle.isActive ? 'Active' : 'Off'}</Badge>
                                                <ServerForm action={`/setup/nozzles/${nozzle.id}/status`} method="PATCH">
                                                    <input name="is_active" type="hidden" value={nozzle.isActive ? '0' : '1'} />
                                                    <SetupIconButton kind={nozzle.isActive ? 'archive' : 'activate'} label={nozzle.isActive ? `Deactivate nozzle ${nozzle.code}` : `Activate nozzle ${nozzle.code}`} type="submit" />
                                                </ServerForm>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </article>
                        ))}
                    </div>
                    <details className="border-t border-slate-100 p-5">
                        <summary className="cursor-pointer text-sm font-bold text-rose-700">＋ Add pump or nozzle</summary>
                        <ServerForm action="/setup/pumps" className="mt-4 grid gap-3 md:grid-cols-2">
                            <input name="branch_id" type="hidden" value={selectedBranchId || ''} />
                            <Field label="Internal pump code"><Input name="code" placeholder="B1-P4" required /></Field>
                            <Field label="Physical pump label"><Input name="label" placeholder="Tatsuno P#4" required /></Field>
                            <div className="flex items-end"><Button type="submit">Add pump</Button></div>
                        </ServerForm>
                        <p className="mt-4 border-t border-slate-100 pt-4 text-sm font-semibold text-slate-600">Connect a nozzle to an active tank</p>
                        <ServerForm action="/setup/nozzles" className="mt-4 grid gap-3 md:grid-cols-2">
                            <input name="branch_id" type="hidden" value={selectedBranchId || ''} />
                            <Field label="Internal pump code"><Input name="pump_code" placeholder="B1-P4" required /></Field>
                            <Field label="Physical pump label"><Input name="pump_label" placeholder="Tatsuno P#4" /></Field>
                            <Field label="Nozzle code"><Input name="nozzle_code" placeholder="B1-P4-N1" required /></Field>
                            <Field label="Connected tank"><Select name="fuel_tank_id" required><option value="">Choose tank</option>{data.tanks.filter((tank) => tank.isActive && (!activeBranchId || String(tank.branchId) === String(activeBranchId))).map((tank) => <option key={tank.id} value={tank.id}>{tank.branchName} · {tank.code} · {tank.productName}</option>)}</Select></Field>
                            <div className="flex items-end"><Button type="submit">Add nozzle</Button></div>
                        </ServerForm>
                    </details>
                </Panel>
            </div>
            <Panel title="Configured tanks" subtitle="Edit measured stock with a reason; archived tanks and their movement history are retained.">
                <DataTable headers={['Tank', 'Fuel · Branch', 'Current stock', 'Status', 'Actions']}>
                    {data.tanks.map((tank) => (
                        <tr key={tank.id}>
                            <td className="px-5 py-4">
                                <p className="font-bold text-slate-900">{tank.code}</p>
                                <p className="mt-1 text-xs text-slate-500">Capacity {formatLiters(tank.capacity)}</p>
                            </td>
                            <td className="px-5 py-4">{tank.productName} · {tank.branchName}</td>
                            <td className="px-5 py-4">{formatLiters(tank.stock)}</td>
                            <td className="px-5 py-4"><Badge tone={tank.isActive ? 'green' : 'slate'}>{tank.isActive ? 'Active' : 'Archived'}</Badge></td>
                            <td className="px-5 py-4">
                                <div className="flex items-center gap-2">
                                    {tank.isActive && <SetupIconButton kind="edit" label={`Edit tank ${tank.code}`} onClick={() => setEditingTankId(tank.id)} />}
                                    <ServerForm action={`/setup/tanks/${tank.id}/status`} method="PATCH">
                                        <input name="is_active" type="hidden" value={tank.isActive ? '0' : '1'} />
                                        <SetupIconButton kind={tank.isActive ? 'archive' : 'activate'} label={tank.isActive ? `Archive tank ${tank.code}` : `Reactivate tank ${tank.code}`} type="submit" />
                                    </ServerForm>
                                </div>
                            </td>
                        </tr>
                    ))}
                    {!data.tanks.length && <tr><td className="px-5 py-8 text-center text-slate-500" colSpan="5">No tanks configured for this branch.</td></tr>}
                </DataTable>
            </Panel>
            {editingTank && editingTank.isActive && <SetupEditModal onClose={() => setEditingTankId(null)} title={`Edit tank · ${editingTank.code}`}>
                <ServerForm action={`/setup/tanks/${editingTank.id}`} className="grid gap-4 sm:grid-cols-2" method="PUT">
                    <Field label="Capacity (L)"><Input defaultValue={editingTank.capacity} min="0.001" name="capacity_liters" required step="0.001" type="number" /></Field>
                    <Field label="Measured stock (L)"><Input defaultValue={editingTank.stock} min="0" name="current_stock_liters" required step="0.001" type="number" /></Field>
                    <Field label="Low stock alert (L)"><Input defaultValue={editingTank.lowStockThreshold ?? ''} min="0" name="low_stock_threshold_liters" step="0.001" type="number" /></Field>
                    <Field label="Reason for stock correction"><Input name="reason" placeholder="Required only when measured stock changes" /></Field>
                    <div className="flex items-end sm:col-span-2"><Button type="submit">Save tank</Button></div>
                </ServerForm>
            </SetupEditModal>}
            <div className="mt-5">
                <Panel title="Fuel products" subtitle="Products with historical use are archived, not removed.">
                    <ServerForm action="/setup/products" className="grid gap-3 p-5 sm:grid-cols-[1fr_auto]">
                        <Field label="New fuel product"><Input name="name" placeholder="Fuel product name" required /></Field>
                        <div className="flex items-end"><Button type="submit">Add fuel</Button></div>
                    </ServerForm>
                    <div className="grid gap-3 border-t border-slate-100 p-5">
                        {data.products.map((product) => <div className="flex flex-wrap items-center justify-between gap-3" key={product.id}>
                            <ServerForm action={`/setup/products/${product.id}`} className="flex min-w-0 flex-1 items-end gap-2" method="PUT">
                                <Field label={product.isActive ? product.name : `${product.name} · archived`}><Input defaultValue={product.name} name="name" required /></Field>
                                <SetupIconButton className="mb-0.5" kind="save" label={`Save ${product.name}`} type="submit" />
                            </ServerForm>
                            <ServerForm action={`/setup/products/${product.id}/status`} method="PATCH">
                                <input name="is_active" type="hidden" value={product.isActive ? '0' : '1'} />
                                <SetupIconButton kind={product.isActive ? 'archive' : 'activate'} label={product.isActive ? `Archive ${product.name}` : `Reactivate ${product.name}`} type="submit" />
                            </ServerForm>
                        </div>)}
                    </div>
                </Panel>
            </div>
        </>
    );
}

function UsersPage({ onSubmit }) {
    return (
        <>
            <PageHeading eyebrow="Team access" title="Team management" description="View station users and prepare branch access." action={<Button>＋ Invite team member</Button>} />
            <div className="mb-5 grid gap-5 xl:grid-cols-[1.1fr_.9fr]">
                <Panel title="Create a staff account" subtitle="Staff can access sales workflows for their assigned branch">
                    <DemoForm className="p-5" onSubmit={onSubmit}>
                        <Field label="Full name"><Input placeholder="Team member name" /></Field>
                        <Field label="Work email"><Input type="email" placeholder="name@fivecegas.com" /></Field>
                        <Field label="Assigned branch"><Select><option>Manaoag</option><option>San Fabian</option></Select></Field>
                        <div className="grid gap-4 sm:grid-cols-2"><Field label="Temporary password"><Input type="password" placeholder="At least 10 characters" /></Field><Field label="Confirm password"><Input type="password" placeholder="Re-enter password" /></Field></div>
                        <Button type="submit">Create account</Button>
                    </DemoForm>
                </Panel>
                <Panel title="Branch-level access" subtitle="The right people have access to the right tools">
                    <div className="grid gap-3 p-5">{[['O', 'Owner', 'Manage station operations and team access', 'bg-blue-100 text-blue-800'], ['S', 'Staff', 'Record sales for an assigned branch', 'bg-rose-100 text-rose-800']].map(([initial, role, description, tone]) => <div className="flex items-center gap-3 rounded-xl border border-slate-100 p-4" key={role}><span className={`grid size-10 place-items-center rounded-full font-extrabold ${tone}`}>{initial}</span><span><strong className="block text-sm text-slate-900">{role}</strong><small className="text-xs text-slate-500">{description}</small></span></div>)}</div>
                </Panel>
            </div>
            <Panel title="Team members" subtitle="3 registered accounts">
                <DataTable headers={['Team member', 'Email', 'Branch', 'Role', 'Status', 'Joined', 'Access']}>{team.map(([name, email, branch, role, status]) => <tr key={email}><td className="px-5 py-4 font-bold text-slate-900">{name}</td><td className="px-5 py-4">{email}</td><td className="px-5 py-4">{branch}</td><td className="px-5 py-4"><Badge tone={role === 'Owner' ? 'blue' : 'slate'}>{role}</Badge></td><td className="px-5 py-4"><Badge>{status}</Badge></td><td className="px-5 py-4">Oct 12, 2026</td><td className="px-5 py-4"><Button className="min-h-8 rounded-lg px-3 py-1 text-xs">Manage</Button></td></tr>)}</DataTable>
            </Panel>
        </>
    );
}

function AuthPage({ root, page }) {
    const [passwordVisible, setPasswordVisible] = useState(false);
    const isForgot = page === 'forgot-password';
    const isReset = page === 'reset-password';
    const title = isReset ? 'Choose a new password' : isForgot ? 'Forgot password?' : 'Welcome back';
    const intro = isReset ? 'Choose a secure password for your account.' : isForgot ? 'Enter your email and we’ll send you a reset link.' : 'Sign in to manage fuel operations and your team.';
    const action = isReset ? '/reset-password' : isForgot ? '/forgot-password' : '/login';
    const submitLabel = isReset ? 'Reset password' : isForgot ? 'Send reset link' : 'Sign in';
    return (
        <main className="grid min-h-screen bg-white lg:grid-cols-2">
            <aside className="relative hidden min-h-screen overflow-hidden bg-slate-900 lg:block">
                <img className="absolute inset-0 size-full object-cover opacity-70" src="/images/fiveCEgasStation.jpg" alt="Five CE Gas Station" />
                <div className="absolute inset-0 bg-gradient-to-br from-slate-950/85 via-blue-950/50 to-rose-950/70" />
                <div className="relative flex h-full min-h-screen flex-col justify-between p-12 text-white xl:p-16">
                    <a className="flex items-center gap-3" href="/login"><img className="size-12 rounded-xl bg-white object-contain p-1" src="/images/fiveCElogo.png" alt="" /><span className="text-sm font-black tracking-[0.2em]">FIVE C E GAS</span></a>
                    <div className="max-w-lg pb-8"><p className="mb-4 text-xs font-bold uppercase tracking-[0.25em] text-rose-200">Fuel operations management</p><h1 className="text-5xl font-black leading-[1.08] xl:text-6xl">Fueling a better tomorrow.</h1><p className="mt-5 max-w-md text-base leading-7 text-white/75">A simpler way to manage inventory, sales, and station operations across every branch.</p></div>
                    <p className="text-xs text-white/60">© 2026 Five CE Gas Station · FiveCETrack</p>
                </div>
            </aside>
            <section className="flex min-h-screen items-center justify-center px-6 py-12 sm:px-12">
                <div className="w-full max-w-md">
                    <a className="mb-10 flex items-center gap-3 lg:hidden" href="/login"><img className="size-11 rounded-xl border border-slate-100 object-contain p-1" src="/images/fiveCElogo.png" alt="" /><span className="text-xs font-black tracking-[0.18em] text-slate-900">FIVE C E GAS</span></a>
                    <p className="mb-2 text-xs font-extrabold uppercase tracking-[0.2em] text-rose-700">Five CE Gas Station</p>
                    <h2 className="text-3xl font-extrabold tracking-tight text-slate-900">{title}</h2>
                    <p className="mt-2 text-sm leading-6 text-slate-500">{intro}</p>
                    {root.dataset.status && <div className="mt-5 rounded-xl bg-emerald-50 p-3 text-sm text-emerald-800" role="status">{root.dataset.status}</div>}
                    {root.dataset.error && <div className="mt-5 rounded-xl bg-rose-50 p-3 text-sm text-rose-800" role="alert">{root.dataset.error}</div>}
                    <form className="mt-8 grid gap-5" method="POST" action={action}>
                        <input type="hidden" name="_token" value={document.querySelector('meta[name="csrf-token"]')?.content ?? ''} />
                        {isReset && <input type="hidden" name="token" value={root.dataset.resetToken ?? ''} />}
                        <Field label="Email address"><Input autoComplete="email" autoFocus defaultValue={root.dataset.email ?? ''} name="email" placeholder="you@example.com" required type="email" /></Field>
                        {!isForgot && <div className="grid gap-1.5 text-sm font-semibold text-slate-700">
                            <label htmlFor="auth-password">{isReset ? 'New password' : 'Password'}</label>
                            <div className="relative">
                                <Input autoComplete={isReset ? 'new-password' : 'current-password'} className="pr-20" id="auth-password" minLength={isReset ? 10 : undefined} name="password" placeholder={isReset ? 'At least 10 characters' : 'Enter your password'} required type={passwordVisible ? 'text' : 'password'} />
                                <button
                                    aria-label={passwordVisible ? 'Hide password' : 'Show password'}
                                    aria-pressed={passwordVisible}
                                    className="absolute inset-y-0 right-3 my-auto grid size-8 place-items-center rounded text-slate-500 hover:text-rose-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-rose-700"
                                    onClick={() => setPasswordVisible((visible) => !visible)}
                                    type="button"
                                >
                                    <svg aria-hidden="true" className="size-5" fill="none" stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="1.8" viewBox="0 0 24 24">
                                        {passwordVisible ? (
                                            <>
                                                <path d="M3 3l18 18" />
                                                <path d="M10.6 10.6a2 2 0 002.8 2.8" />
                                                <path d="M9.9 5.2A11 11 0 0112 5c5 0 8.3 4.5 9 7-.3 1.1-1.1 2.4-2.3 3.6" />
                                                <path d="M6.2 6.2C3.9 7.6 2.4 10 2 12c.7 2.5 4 7 10 7a10 10 0 004.1-.9" />
                                            </>
                                        ) : (
                                            <>
                                                <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7z" />
                                                <circle cx="12" cy="12" r="3" />
                                            </>
                                        )}
                                    </svg>
                                </button>
                            </div>
                        </div>}
                        {isReset && <Field label="Confirm new password"><Input autoComplete="new-password" minLength={10} name="password_confirmation" placeholder="Re-enter your new password" required type="password" /></Field>}
                        {!isForgot && !isReset && <div className="flex items-center justify-between gap-3 text-sm"><label className="flex items-center gap-2 text-slate-600"><input className="size-4 rounded border-slate-300 accent-rose-700" name="remember" type="checkbox" value="1" />Remember me</label><a className="font-bold text-rose-700 hover:text-rose-900" href="/forgot-password">Forgot password?</a></div>}
                        <Button className="mt-1 min-h-12 w-full" type="submit">{submitLabel} <span aria-hidden="true">→</span></Button>
                    </form>
                    {(isForgot || isReset) && <a className="mt-6 inline-flex text-sm font-bold text-rose-700 hover:text-rose-900" href="/login">← Back to sign in</a>}
                    <div className="mt-10 flex items-center gap-3 rounded-2xl bg-slate-50 p-4"><span className="grid size-10 shrink-0 place-items-center rounded-xl bg-white text-lg text-blue-800 shadow-sm">▣</span><p className="text-xs leading-5 text-slate-500">Authorized personnel only. Your sign-in is protected by secure account access.</p></div>
                </div>
            </section>
        </main>
    );
}

function App({ root }) {
    const [mobileNavOpen, setMobileNavOpen] = useState(false);
    const [notice, setNotice] = useState('');
    const [workspace, setWorkspace] = useState(null);
    const [workspaceError, setWorkspaceError] = useState('');
    const [notifications, setNotifications] = useState([]);
    const [unreadNotifications, setUnreadNotifications] = useState(0);
    const [notificationsOpen, setNotificationsOpen] = useState(false);
    const [notificationsLoading, setNotificationsLoading] = useState(false);
    const [notificationError, setNotificationError] = useState('');
    const [notificationActionBusy, setNotificationActionBusy] = useState(false);
    const notificationsRef = useRef(null);
    const page = root.dataset.page || 'dashboard';
    const authenticated = root.dataset.authenticated === 'true';
    const isOwner = root.dataset.isOwner === 'true';
    const canSwitchBranch = root.dataset.canSwitchBranch === 'true';
    const branchOptions = JSON.parse(root.dataset.branchOptions || '[]');
    const userName = root.dataset.userName || 'Station Manager';
    const setupData = page === 'setup' ? JSON.parse(root.dataset.setupData || '{"branches":[],"products":[],"tanks":[],"pumps":[]}') : null;

    const loadNotifications = useCallback(async () => {
        setNotificationsLoading(true);
        setNotificationError('');
        try {
            const response = await requestJson(`${root.dataset.notificationsUrl}?per_page=25`);
            setNotifications(response.data?.data ?? []);
            setUnreadNotifications(response.unread_count ?? 0);
        } catch (error) {
            setNotificationError(error.message);
        } finally {
            setNotificationsLoading(false);
        }
    }, [root.dataset.notificationsUrl]);

    useEffect(() => {
        if (!authenticated) return undefined;
        loadNotifications();
        const interval = window.setInterval(loadNotifications, 60000);
        window.addEventListener('focus', loadNotifications);
        return () => {
            window.clearInterval(interval);
            window.removeEventListener('focus', loadNotifications);
        };
    }, [authenticated, loadNotifications]);

    useEffect(() => {
        if (!notificationsOpen) return undefined;
        function closeOnOutsideClick(event) {
            if (!notificationsRef.current?.contains(event.target)) setNotificationsOpen(false);
        }
        function closeOnEscape(event) {
            if (event.key === 'Escape') setNotificationsOpen(false);
        }
        document.addEventListener('pointerdown', closeOnOutsideClick);
        document.addEventListener('keydown', closeOnEscape);
        return () => {
            document.removeEventListener('pointerdown', closeOnOutsideClick);
            document.removeEventListener('keydown', closeOnEscape);
        };
    }, [notificationsOpen]);

    async function markNotificationRead(notification) {
        if (notification.read_at) return;
        setNotificationActionBusy(true);
        setNotificationError('');
        try {
            const url = root.dataset.notificationReadUrlTemplate
                .replace('__NOTIFICATION_ID__', encodeURIComponent(notification.id));
            const response = await requestJson(url, { method: 'PATCH' });
            setNotifications((current) => current.map((item) => item.id === notification.id
                ? { ...item, read_at: response.data.read_at }
                : item));
            setUnreadNotifications((count) => Math.max(0, count - 1));
        } catch (error) {
            setNotificationError(error.message);
        } finally {
            setNotificationActionBusy(false);
        }
    }

    async function markAllNotificationsRead() {
        if (!unreadNotifications) return;
        setNotificationActionBusy(true);
        setNotificationError('');
        try {
            await requestJson(root.dataset.notificationsReadAllUrl, { method: 'PATCH' });
            const readAt = new Date().toISOString();
            setNotifications((current) => current.map((item) => ({ ...item, read_at: item.read_at || readAt })));
            setUnreadNotifications(0);
        } catch (error) {
            setNotificationError(error.message);
        } finally {
            setNotificationActionBusy(false);
        }
    }

    useEffect(() => {
        let cancelled = false;
        const workspaceUrl = new URL(root.dataset.workspaceUrl, window.location.origin);
        const pageUrl = new URL(window.location.href);
        ['sales_page', 'my_sales'].forEach((parameter) => {
            if (pageUrl.searchParams.has(parameter)) {
                workspaceUrl.searchParams.set(parameter, pageUrl.searchParams.get(parameter));
            }
        });
        requestJson(workspaceUrl)
            .then((data) => {
                if (!cancelled) setWorkspace(data);
            })
            .catch((error) => {
                if (!cancelled) setWorkspaceError(error.message);
            });

        return () => {
            cancelled = true;
        };
    }, [root]);

    function submitDemo(event) {
        event.preventDefault();
        setNotice('This screen is a UI preview. Data actions will be connected in a later step.');
    }

    if (!authenticated) return <AuthPage root={root} page={page} />;

    const pageContent = {
        dashboard: workspace && <WorkspacePage data={workspace} page="dashboard" root={root} userName={userName} />,
        inventory: workspace && <WorkspacePage data={workspace} page="inventory" root={root} userName={userName} />,
        sales: workspace && <WorkspacePage data={workspace} page="sales" root={root} userName={userName} />,
        transactions: workspace && <WorkspacePage data={workspace} page="transactions" root={root} userName={userName} />,
        deliveries: workspace && <WorkspacePage data={workspace} page="deliveries" root={root} userName={userName} />,
        adjustments: workspace && <WorkspacePage data={workspace} page="adjustments" root={root} userName={userName} />,
        prices: workspace && <WorkspacePage data={workspace} page="prices" root={root} userName={userName} />,
        reports: workspace && <WorkspacePage data={workspace} page="reports" root={root} userName={userName} />,
        credit: <FuelCredit root={root} />,
        setup: <SetupPage
            activeBranchId={root.dataset.activeBranchId}
            data={setupData}
            error={root.dataset.error}
            status={root.dataset.status}
        />,
        users: workspace && <WorkspacePage data={workspace} page="users" root={root} userName={userName} />,
    };
    const visibleNavigation = navigation.filter((item) => isOwner || ['sales', 'transactions'].includes(item.page));

    return (
        <div className="min-h-screen bg-[#f5f6fa] text-slate-800">
            {mobileNavOpen && <button aria-label="Close navigation" className="fixed inset-0 z-30 bg-slate-950/50 lg:hidden" onClick={() => setMobileNavOpen(false)} type="button" />}
            <aside className={`fixed inset-y-0 left-0 z-40 flex w-[260px] flex-col overflow-y-auto bg-[linear-gradient(180deg,#111d2e_0%,#192333_34%,#402b3c_66%,#9b343e_100%)] text-white shadow-xl transition-transform lg:translate-x-0 ${mobileNavOpen ? 'translate-x-0' : '-translate-x-full'}`}>
                <a className="flex h-[84px] items-center gap-2.5 border-b border-white/10 px-5" href="/">
                    <img className="size-11 shrink-0 object-contain" src="/images/fiveCElogo.png" alt="" />
                    <strong className="text-sm font-black tracking-[0.08em]">FIVE C E GAS</strong>
                </a>
                {canSwitchBranch ? (
                    <form action="/branch-context" className="mx-4 mt-5" method="POST">
                        <input name="_token" type="hidden" value={document.querySelector('meta[name="csrf-token"]')?.content ?? ''} />
                        <label className="mb-1.5 block text-[10px] font-bold uppercase tracking-wider text-white/50" htmlFor="active-branch">Active branch</label>
                        <Select
                            className="!min-h-10 !border-white/10 !bg-white/[0.06] !px-3 !py-2 !text-xs !text-white focus:!border-rose-300 focus:!ring-rose-300/20"
                            defaultValue={root.dataset.activeBranchId || ''}
                            id="active-branch"
                            name="branch_id"
                            onChange={(event) => event.currentTarget.form?.requestSubmit()}
                            required
                        >
                            {branchOptions.map((branch) => <option className="text-slate-900" key={branch.id} value={branch.id}>{branch.name}</option>)}
                        </Select>
                    </form>
                ) : (
                    <div className="mx-4 mt-5 flex items-center gap-2 rounded-xl border border-white/10 bg-white/[0.06] px-3 py-3 text-xs font-semibold text-white/80"><span className="size-2 rounded-full bg-emerald-400 shadow-[0_0_10px_#34d399]" />{root.dataset.branchName || 'Manaoag branch'}<span className="ml-auto text-white/40">⌄</span></div>
                )}
                <nav aria-label="Main navigation" className="mt-6 flex-1 space-y-1 px-3">
                    <p className="px-3 pb-2 text-[9px] font-extrabold uppercase tracking-[0.2em] text-white/40">Workspace</p>
                    {visibleNavigation.map((item) => {
                        const active = page === item.page;
                        return <a className={`flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-semibold transition ${active ? 'primary-gradient text-white shadow-md shadow-black/15' : 'text-white/75 hover:bg-white/[0.09] hover:text-white'}`} href={item.href} key={item.label} onClick={() => setMobileNavOpen(false)}><span className="grid size-6 place-items-center text-base">{item.page === 'sales' ? <svg aria-hidden="true" className="size-5" fill="none" stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="1.7" viewBox="0 0 24 24"><path d="M5 3h14v18l-2-1.5L15 21l-3-1.5L9 21l-2-1.5L5 21V3Z" /><path d="M9 8h6M9 12h2" /><path d="M14.5 15.5c0-.8-.6-1.2-1.5-1.2s-1.5.4-1.5 1.2.6 1.2 1.5 1.2 1.5.4 1.5 1.2-.6 1.2-1.5 1.2-1.5-.4-1.5-1.2M13 13.5v7" /></svg> : item.icon}</span><span>{item.label}</span>{active && <span className="ml-auto size-1.5 rounded-full bg-white/80" />}</a>;
                    })}
                </nav>
                <div className="border-t border-white/15 p-4">
                    <div className="flex items-center gap-3 rounded-lg bg-black/10 p-3"><span className="grid size-9 place-items-center rounded-full bg-white/15 text-sm font-extrabold">{userName.slice(0, 1).toUpperCase()}</span><span className="min-w-0 flex-1"><strong className="block truncate text-xs">{userName}</strong><small className="text-[10px] capitalize text-white/65">{root.dataset.role || (isOwner ? 'Owner' : 'Staff')}</small></span><form action="/logout" method="POST"><input type="hidden" name="_token" value={document.querySelector('meta[name="csrf-token"]')?.content ?? ''} /><Button aria-label="Log out" className="size-9 min-h-9 rounded-lg px-0 py-1 text-xs" type="submit">↗</Button></form></div>
                </div>
            </aside>
            <div className="min-h-screen min-w-0 lg:pl-[260px]">
                <header className="sticky top-0 z-20 flex h-[72px] items-center justify-between border-b border-slate-200/80 bg-white/90 px-4 backdrop-blur-md sm:px-7">
                    <div className="flex items-center gap-3"><Button aria-label="Open navigation" className="grid size-10 min-h-10 place-items-center rounded-xl px-0 text-lg lg:hidden" onClick={() => setMobileNavOpen(true)}>☰</Button><div className="text-xs text-slate-400 sm:text-sm"><span>Five CE Gas</span><span className="mx-2">/</span><strong className="text-slate-800">{pageTitles[page] || 'Overview'}</strong></div></div>
                    <div className="flex items-center gap-3 sm:gap-5">
                        <div className="relative" ref={notificationsRef}>
                            <Button
                                aria-controls="notifications-panel"
                                aria-expanded={notificationsOpen}
                                aria-label={unreadNotifications ? `Notifications, ${unreadNotifications} unread` : 'Notifications'}
                                className="relative grid size-10 min-h-10 place-items-center rounded-xl px-0"
                                onClick={() => {
                                    const nextOpen = !notificationsOpen;
                                    setNotificationsOpen(nextOpen);
                                    if (nextOpen) loadNotifications();
                                }}
                                type="button"
                            >
                                <svg aria-hidden="true" className="size-5" fill="none" stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="1.8" viewBox="0 0 24 24">
                                    <path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9" />
                                    <path d="M10 21h4" />
                                </svg>
                                {unreadNotifications > 0 && <span aria-hidden="true" className="absolute -right-1 -top-1 grid min-h-4 min-w-4 place-items-center rounded-full bg-rose-600 px-1 text-[9px] font-extrabold leading-none text-white ring-2 ring-white">{unreadNotifications > 99 ? '99+' : unreadNotifications}</span>}
                            </Button>
                            {notificationsOpen && <section
                                aria-label="Notifications"
                                className="absolute right-0 z-[60] mt-3 w-[min(90vw,24rem)] overflow-hidden rounded-2xl border border-slate-200 bg-white text-slate-800 shadow-2xl"
                                id="notifications-panel"
                            >
                                <div className="flex items-center justify-between border-b border-slate-100 px-4 py-3">
                                    <div><h2 className="text-sm font-bold text-slate-900">Notifications</h2><p className="mt-0.5 text-xs text-slate-500">{unreadNotifications} unread</p></div>
                                    <div className="flex items-center gap-3">
                                        {unreadNotifications > 0 && <button className="text-xs font-bold text-rose-700 hover:text-rose-900 disabled:opacity-50" disabled={notificationActionBusy} onClick={markAllNotificationsRead} type="button">Mark all read</button>}
                                        <button aria-label="Close notifications" className="grid size-7 place-items-center rounded-lg text-slate-500 hover:bg-slate-100" onClick={() => setNotificationsOpen(false)} type="button">×</button>
                                    </div>
                                </div>
                                {notificationError && <p className="border-b border-rose-100 bg-rose-50 px-4 py-2 text-xs text-rose-800" role="alert">{notificationError}</p>}
                                {notificationsLoading && !notifications.length
                                    ? <p className="px-4 py-8 text-center text-sm text-slate-500" role="status">Loading notifications…</p>
                                    : notifications.length
                                        ? <ul className="max-h-[min(65vh,28rem)] divide-y divide-slate-100 overflow-y-auto">
                                            {notifications.map((item) => {
                                                const details = item.data ?? {};
                                                const lowStock = details.event === 'low_stock_threshold_reached';
                                                const title = lowStock ? 'Low fuel stock' : String(details.event || item.type || 'Station notification').replaceAll('_', ' ');
                                                const description = lowStock
                                                    ? `${details.branch} · ${details.tank_code} (${details.fuel})`
                                                    : (details.message || 'There is a new station update.');
                                                const detailLine = lowStock
                                                    ? `${Number(details.stock_liters ?? 0).toLocaleString('en-PH')} L remaining · threshold ${Number(details.threshold_liters ?? 0).toLocaleString('en-PH')} L`
                                                    : null;
                                                const timestamp = item.created_at
                                                    ? new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(item.created_at))
                                                    : '';
                                                return <li className={`px-4 py-3 ${item.read_at ? 'bg-white' : 'bg-rose-50/50'}`} key={item.id}>
                                                    <div className="flex items-start gap-3">
                                                        <span aria-hidden="true" className={`mt-1 size-2 shrink-0 rounded-full ${item.read_at ? 'bg-slate-300' : 'bg-rose-600'}`} />
                                                        <div className="min-w-0 flex-1">
                                                            <p className="text-sm font-bold capitalize text-slate-900">{title}</p>
                                                            <p className="mt-1 text-xs text-slate-600">{description}</p>
                                                            {detailLine && <p className="mt-1 text-xs font-semibold text-rose-700">{detailLine}</p>}
                                                            <div className="mt-2 flex items-center justify-between gap-3">
                                                                <a className="text-xs font-bold text-blue-700 hover:underline" href="/inventory" onClick={() => setNotificationsOpen(false)}>View inventory</a>
                                                                <span className="text-[10px] text-slate-400">{timestamp}</span>
                                                            </div>
                                                            {!item.read_at && <button className="mt-2 text-xs font-semibold text-slate-500 hover:text-slate-800 disabled:opacity-50" disabled={notificationActionBusy} onClick={() => markNotificationRead(item)} type="button">Mark as read</button>}
                                                        </div>
                                                    </div>
                                                </li>;
                                            })}
                                        </ul>
                                        : <p className="px-4 py-8 text-center text-sm text-slate-500">{notificationError ? 'Notifications could not be loaded.' : 'You’re all caught up.'}</p>}
                                {!notificationError && notifications.length > 0 && <p className="border-t border-slate-100 px-4 py-2 text-center text-[10px] text-slate-400">Showing the latest {notifications.length} notifications · refreshes automatically</p>}
                            </section>}
                        </div>
                        <span className="grid size-9 place-items-center rounded-full bg-gradient-to-br from-blue-700 to-rose-600 text-xs font-extrabold text-white">{userName.slice(0, 1).toUpperCase()}</span>
                    </div>
                </header>
                <main className="workspace-content mx-auto min-w-0 max-w-[1500px] px-4 py-6 sm:px-7 sm:py-8">
                    {notice && <div className="mb-5 flex items-start justify-between gap-4 rounded-xl border border-blue-100 bg-blue-50 px-4 py-3 text-sm text-blue-800" role="status">{notice}<Button aria-label="Dismiss message" className="min-h-7 rounded-lg px-2 py-1 text-xs" onClick={() => setNotice('')}>×</Button></div>}
                    {page !== 'setup' && root.dataset.status && <div className="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800" role="status">{root.dataset.status}</div>}
                    {page !== 'setup' && root.dataset.error && <div className="mb-5 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-medium text-rose-800" role="alert">{root.dataset.error}</div>}
                    {workspaceError && <div className="mb-5 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-medium text-rose-800" role="alert">Could not load station data: {workspaceError}</div>}
                    {!workspace && !workspaceError && <p className="rounded-xl border border-slate-200 bg-white p-5 text-sm text-slate-500">Loading live station data…</p>}
                    {pageContent[page] || (workspace && <WorkspacePage data={workspace} page="dashboard" root={root} userName={userName} />)}
                </main>
            </div>
        </div>
    );
}

export default App;

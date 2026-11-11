import React, { useEffect, useMemo, useRef, useState } from 'react';
import { requestJson } from '../api.js';

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';
const money = (value) => `₱${Number(value ?? 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const liters = (value) => `${Number(value ?? 0).toLocaleString('en-PH', { maximumFractionDigits: 3 })} L`;
const dateTime = (value) => value
    ? new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
    : '—';
const fuelOrder = (name) => {
    const normalized = name.toLowerCase();
    if (normalized.includes('unleaded')) return 0;
    if (normalized.includes('premium')) return 1;
    if (normalized.includes('diesel')) return 2;
    return 3;
};
const compareFuelNames = (left, right) => fuelOrder(left) - fuelOrder(right) || left.localeCompare(right);
const localDateTime = (offsetMinutes = 0) => {
    const now = new Date();
    return new Date(now.getTime() + offsetMinutes * 60000 - now.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
};

function ActionButton({ children, className = '', ...props }) {
    return <button className={`primary-gradient inline-flex min-h-9 items-center justify-center rounded-xl px-3 py-2 text-xs font-bold text-white shadow-sm transition disabled:opacity-60 ${className}`} {...props}>{children}</button>;
}

function Panel({ title, children, subtitle, action, allowOverflow = false }) {
    return (
        <section className={`min-w-0 ${allowOverflow ? 'overflow-visible' : 'overflow-hidden'} rounded-2xl border border-slate-200 bg-white shadow-sm`}>
            <header className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-4">
                <div><h2 className="font-bold text-slate-900">{title}</h2>{subtitle && <p className="mt-1 text-xs text-slate-500">{subtitle}</p>}</div>
                {action}
            </header>
            {children}
        </section>
    );
}

function Field({ label, children }) {
    return <label className="grid min-w-0 gap-1.5 text-sm font-semibold text-slate-700">{label}{children}</label>;
}

function Input(props) {
    return <input className="min-h-10 min-w-0 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm text-slate-800 outline-none focus:border-rose-400 focus:ring-4 focus:ring-rose-100" {...props} />;
}

function Select({ children, ...props }) {
    return <select className="min-h-10 min-w-0 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800 outline-none focus:border-rose-400 focus:ring-4 focus:ring-rose-100" {...props}>{children}</select>;
}

function Form({ action, method = 'POST', children, className = '', encType, onSubmit }) {
    return (
        <form action={action} className={className} encType={encType} method="POST" onSubmit={onSubmit}>
            <input name="_token" type="hidden" value={csrf()} />
            {method !== 'POST' && <input name="_method" type="hidden" value={method} />}
            {children}
        </form>
    );
}

function EditModal({ title, onClose, children }) {
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
            <section aria-labelledby="edit-modal-title" aria-modal="true" className="my-auto max-h-[90vh] w-full max-w-3xl overflow-y-auto rounded-2xl bg-white shadow-2xl" role="dialog">
                <header className="sticky top-0 z-10 flex items-center justify-between gap-4 border-b border-slate-100 bg-white px-5 py-4">
                    <h2 className="text-lg font-bold text-slate-900" id="edit-modal-title">{title}</h2>
                    <button aria-label="Close edit dialog" className="grid size-9 place-items-center rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50" onClick={onClose} type="button">
                        <svg aria-hidden="true" className="size-4" fill="none" stroke="currentColor" strokeLinecap="round" strokeWidth="2" viewBox="0 0 24 24"><path d="m6 6 12 12M18 6 6 18" /></svg>
                    </button>
                </header>
                <div className="p-5">{children}</div>
            </section>
        </div>
    );
}

function Table({ headers, children }) {
    return (
        <div className="min-w-0 max-w-full overflow-x-auto">
            <table className="w-full min-w-[760px] text-left text-sm">
                <thead className="bg-slate-50 text-[11px] font-extrabold uppercase tracking-wider text-slate-500"><tr>{headers.map((header) => <th className="px-4 py-3" key={header}>{header}</th>)}</tr></thead>
                <tbody className="divide-y divide-slate-100 text-slate-700">{children}</tbody>
            </table>
        </div>
    );
}

function PageNavigation({ pagination, parameter }) {
    if (!pagination || pagination.last_page < 2) return null;
    const pageUrl = (page) => {
        const url = new URL(window.location.href);
        if (page <= 1) url.searchParams.delete(parameter);
        else url.searchParams.set(parameter, page);
        return `${url.pathname}${url.search}${url.hash}`;
    };

    return (
        <nav aria-label="Pagination" className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 px-4 py-3 text-xs">
            <span className="text-slate-500">Page {pagination.current_page} of {pagination.last_page} · {pagination.total} records</span>
            <div className="flex gap-2">
                {pagination.current_page > 1 && <a className="rounded-lg border border-slate-200 px-3 py-2 font-bold text-slate-700" href={pageUrl(pagination.current_page - 1)}>Previous</a>}
                {pagination.current_page < pagination.last_page && <a className="rounded-lg border border-slate-200 px-3 py-2 font-bold text-slate-700" href={pageUrl(pagination.current_page + 1)}>Next</a>}
            </div>
        </nav>
    );
}

function Status({ children }) {
    const tone = String(children).toLowerCase();
    const styles = tone.includes('void') || tone.includes('reject') || tone.includes('inactive')
        ? 'bg-slate-100 text-slate-600'
        : tone.includes('pending') || tone.includes('active')
            ? 'bg-amber-50 text-amber-700'
            : 'bg-emerald-50 text-emerald-700';
    return <span className={`inline-flex rounded-full px-2.5 py-1 text-xs font-bold ${styles}`}>{children}</span>;
}

function BranchField({ root, data }) {
    if (root.dataset.canSwitchBranch !== 'true') return null;
    const activeBranch = data.branches.find((branch) => String(branch.id) === root.dataset.activeBranchId);
    return (
        <>
            <input name="branch_id" type="hidden" value={root.dataset.activeBranchId || ''} />
            <Field label="Branch"><p className="flex min-h-10 items-center rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700">{activeBranch?.name || 'Select an active branch from the sidebar'}</p></Field>
        </>
    );
}

function SalesTable({ sales }) {
    const [editingSaleId, setEditingSaleId] = useState(null);
    const editingSale = sales.find((sale) => sale.id === editingSaleId);
    return (
        <>
            <Table headers={['Receipt', 'Fuel', 'Branch · pump/nozzle', 'Date & time', 'Recorded by', 'Liters', 'Amount', 'Source / status', 'Actions']}>
                {sales.map((sale) => (
                    <tr key={sale.id}>
                        <td className="px-4 py-3 font-semibold">{sale.receipt || `Sale #${sale.id}`}</td>
                        <td className="px-4 py-3">{sale.fuel}</td>
                        <td className="px-4 py-3">{sale.branch} · {sale.pump} / {sale.nozzle}</td>
                        <td className="px-4 py-3 whitespace-nowrap">{dateTime(sale.sold_at)}</td>
                        <td className="px-4 py-3">{sale.recorder}</td>
                        <td className="px-4 py-3">{liters(sale.liters)}</td>
                        <td className="px-4 py-3 font-bold">{money(sale.amount)}</td>
                        <td className="px-4 py-3"><div className="grid justify-items-start gap-1"><Status>{sale.status}</Status>{sale.is_imported && <span className="text-[10px] font-bold text-slate-500">Imported · read-only</span>}</div></td>
                        <td className="px-4 py-3">
                            {sale.can_edit ? <button aria-label="Edit sale" className="grid size-9 place-items-center rounded-lg border border-violet-200 text-violet-600 transition hover:bg-violet-50" onClick={() => setEditingSaleId(sale.id)} title="Edit" type="button">
                                <svg aria-hidden="true" className="size-4" fill="none" stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="1.8" viewBox="0 0 24 24"><path d="m15 5 4 4M4 20l4-.8L19 8a2.1 2.1 0 0 0-3-3L5 16l-1 4Z" /></svg>
                            </button> : <span className="text-xs text-slate-400">{sale.is_imported ? 'Locked' : '—'}</span>}
                        </td>
                    </tr>
                ))}
                {!sales.length && <tr><td className="px-4 py-8 text-center text-slate-500" colSpan="9">No sales recorded in this database yet.</td></tr>}
            </Table>
            {editingSale && <EditModal onClose={() => setEditingSaleId(null)} title={`Edit sale · ${editingSale.receipt || `Sale #${editingSale.id}`}`}>
                <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_minmax(15rem,.45fr)]">
                    <Form action={`/sales/${editingSale.id}`} className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3" method="PUT">
                        <Field label="Receipt number"><Input defaultValue={editingSale.receipt ?? ''} maxLength="100" name="receipt_number" /></Field>
                        <Field label="Liters"><Input defaultValue={editingSale.liters} min="0.001" name="liters" required step="0.001" type="number" /></Field>
                        <Field label="Payment method"><Select defaultValue={editingSale.payment_method || ''} name="payment_method"><option value="">Not specified</option><option value="cash">Cash</option><option value="card">Card</option><option value="ewallet">E-wallet</option><option value="other">Other</option></Select></Field>
                        <Field label="Correction reason"><Input name="edit_reason" placeholder="Required for audit" required /></Field>
                        <div className="flex items-end"><ActionButton type="submit">Save correction</ActionButton></div>
                    </Form>
                    <Form action={`/sales/${editingSale.id}/void`} className="grid content-start gap-3 border-t border-slate-200 pt-3 xl:border-l xl:border-t-0 xl:pl-4 xl:pt-0" method="POST">
                        <Field label="Void reason"><Input name="void_reason" required /></Field>
                        <ActionButton type="submit">Void and reverse stock</ActionButton>
                    </Form>
                </div>
            </EditModal>}
        </>
    );
}

function Dashboard({ data, userName }) {
    const summary = data.summary;
    const fuelSummary = data.inventory.reduce((products, tank) => {
        const current = products.get(tank.fuel) || {
            fuel: tank.fuel,
            stock: 0,
            capacity: 0,
            minimum: 0,
        };
        current.stock += tank.stock_liters;
        current.capacity += tank.capacity_liters;
        current.minimum += tank.low_stock_threshold_liters ?? 0;
        products.set(tank.fuel, current);
        return products;
    }, new Map());
    const fuelCards = [...fuelSummary.values()].sort((left, right) => compareFuelNames(left.fuel, right.fuel));
    const chartMaximum = Math.max(1, ...fuelCards.map((fuel) => fuel.capacity));
    const fuelTone = (index) => [
        { icon: 'text-emerald-700', soft: 'bg-emerald-50', label: 'text-emerald-800' },
        { icon: 'text-rose-700', soft: 'bg-rose-50', label: 'text-rose-800' },
        { icon: 'text-amber-600', soft: 'bg-amber-50', label: 'text-amber-800' },
    ][index % 3];

    return (
        <div className="grid min-w-0 gap-5">
            <div>
                <h1 className="text-2xl font-extrabold tracking-tight text-slate-900 sm:text-3xl">Dashboard</h1>
                <p className="mt-1 text-sm text-slate-500">Welcome back, {userName}. Current fuel stock: {liters(summary.stock_liters)}.</p>
            </div>
            <div className="grid min-w-0 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                {fuelCards.map((fuel, index) => {
                    const tone = fuelTone(index);
                    const low = fuel.minimum > 0 && fuel.stock <= fuel.minimum;
                    return (
                        <article className="flex min-w-0 items-center gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:gap-4 sm:p-5" key={fuel.fuel}>
                            <span className={`grid size-12 shrink-0 place-items-center rounded-xl ${tone.soft} ${tone.icon}`} aria-hidden="true">
                                <svg className="size-8" fill="none" stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="1.8" viewBox="0 0 32 32">
                                    <path d="M7 27V6a2 2 0 0 1 2-2h11a2 2 0 0 1 2 2v21M5 27h19M9 8h11v7H9zM22 10h3l3 4v9a2 2 0 0 1-4 0v-5h-2" />
                                </svg>
                            </span>
                            <div className="min-w-0">
                                <p className={`text-xs font-bold ${tone.label}`}>{fuel.fuel}</p>
                                <p className="truncate text-xl font-extrabold tracking-tight text-slate-900">{liters(fuel.stock)}</p>
                                <span className={`mt-1 inline-flex rounded-full px-2 py-0.5 text-[10px] font-bold ${low ? 'bg-rose-100 text-rose-800' : 'bg-emerald-100 text-emerald-800'}`}>
                                    {low ? 'Low stock' : 'In stock'}
                                </span>
                            </div>
                        </article>
                    );
                })}
                {!fuelCards.length && <p className="rounded-xl border border-slate-200 bg-white p-5 text-sm text-slate-500">No fuel inventory is configured for this branch.</p>}
            </div>
            <div className="grid min-w-0 items-stretch gap-5 xl:grid-cols-2">
                <Panel title="Fuel inventory level" subtitle="Current stock compared with configured low-stock thresholds">
                    <div className="grid min-h-64 grid-cols-2 items-end gap-3 px-4 pb-5 pt-4 sm:grid-cols-3 sm:gap-5 sm:px-6">
                        {fuelCards.map((fuel, index) => {
                            const tone = fuelTone(index);
                            const stockHeight = Math.max(fuel.stock > 0 ? 3 : 0, fuel.stock / chartMaximum * 100);
                            const minimumHeight = fuel.minimum > 0 ? Math.max(2, fuel.minimum / chartMaximum * 100) : 0;
                            return (
                                <div className="grid min-w-0 gap-2 text-center" key={fuel.fuel}>
                                    <div className="flex h-44 items-end justify-center gap-2 border-b border-slate-200 px-2">
                                        <div aria-label={`${fuel.fuel} current stock ${liters(fuel.stock)}`} className="w-8 max-w-[42%] rounded-t-md bg-blue-600" style={{ height: `${Math.min(100, stockHeight)}%` }} title={`Current stock: ${liters(fuel.stock)}`} />
                                        <div aria-label={`${fuel.fuel} low-stock threshold ${liters(fuel.minimum)}`} className="w-8 max-w-[42%] rounded-t-md bg-slate-300" style={{ height: `${Math.min(100, minimumHeight)}%` }} title={`Low-stock threshold: ${liters(fuel.minimum)}`} />
                                    </div>
                                    <strong className="truncate text-xs text-slate-700">{fuel.fuel}</strong>
                                    <span className="text-[10px] text-slate-500">{liters(fuel.stock)} on hand</span>
                                </div>
                            );
                        })}
                        {!fuelCards.length && <p className="col-span-full self-center py-10 text-center text-sm text-slate-500">No stock data to chart.</p>}
                    </div>
                    <div className="flex flex-wrap gap-4 border-t border-slate-100 px-5 py-3 text-xs text-slate-600">
                        <span className="flex items-center gap-2"><i className="size-2.5 rounded-sm bg-blue-600" />Current stock</span>
                        <span className="flex items-center gap-2"><i className="size-2.5 rounded-sm bg-slate-300" />Low-stock threshold</span>
                    </div>
                </Panel>
                <Panel title="Recent transactions" subtitle="Latest recorded sales">
                    <Table headers={['Date', 'Fuel', 'Branch', 'Liters', 'Amount']}>
                        {data.sales.slice(0, 6).map((sale) => (
                            <tr key={sale.id}>
                                <td className="whitespace-nowrap px-4 py-3 text-xs">{dateTime(sale.sold_at)}</td>
                                <td className="px-4 py-3 font-semibold">{sale.fuel}</td>
                                <td className="px-4 py-3">{sale.branch}</td>
                                <td className="whitespace-nowrap px-4 py-3">{liters(sale.liters)}</td>
                                <td className="whitespace-nowrap px-4 py-3 font-bold">{money(sale.amount)}</td>
                            </tr>
                        ))}
                        {!data.sales.length && <tr><td className="px-4 py-8 text-center text-slate-500" colSpan="5">No sales recorded in this branch yet.</td></tr>}
                    </Table>
                </Panel>
            </div>
            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                {[
                    ['Today’s sales', money(summary.today_sales)],
                    ['Fuel dispensed today', liters(summary.today_liters)],
                    ['Recorded transactions', summary.today_transaction_count],
                    ['Pending reviews', summary.pending_deliveries + summary.pending_adjustments],
                ].map(([label, value]) => <article className="rounded-xl border border-slate-200 bg-white p-4" key={label}><p className="text-xs font-medium text-slate-500">{label}</p><p className="mt-1 text-lg font-extrabold text-slate-900">{value}</p></article>)}
            </div>
        </div>
    );
}

function Inventory({ data }) {
    const branchName = data.branches.find((branch) => branch.id === data.active_branch_id)?.name;
    const fuelSummary = data.inventory.reduce((groups, tank) => {
        const group = groups.get(tank.fuel) || {
            fuel: tank.fuel,
            stock: 0,
            capacity: 0,
            minimum: 0,
            tankCount: 0,
            activeCount: 0,
        };
        group.stock += tank.stock_liters;
        group.capacity += tank.capacity_liters;
        group.minimum += tank.low_stock_threshold_liters ?? 0;
        group.tankCount += 1;
        if (tank.is_active) group.activeCount += 1;
        groups.set(tank.fuel, group);
        return groups;
    }, new Map());
    const fuels = [...fuelSummary.values()].sort((left, right) => compareFuelNames(left.fuel, right.fuel));
    const tones = [
        { icon: 'bg-emerald-50 text-emerald-700', bar: 'bg-emerald-600', value: 'text-emerald-700' },
        { icon: 'bg-rose-50 text-rose-700', bar: 'bg-rose-600', value: 'text-rose-700' },
        { icon: 'bg-amber-50 text-amber-700', bar: 'bg-amber-500', value: 'text-amber-700' },
    ];

    return (
        <>
            <div className="mb-6 flex flex-wrap items-end justify-between gap-3">
                <div><h1 className="text-3xl font-extrabold text-slate-900">Fuel inventory</h1><p className="mt-1 text-sm text-slate-500">Monitor current fuel stock{branchName ? ` at ${branchName}` : ''}.</p></div>
                <a className="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50" href="/setup">Manage tanks</a>
            </div>
            <div className="mb-5 grid min-w-0 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                {fuels.map((fuel, index) => {
                    const tone = tones[index % tones.length];
                    const percent = fuel.capacity > 0 ? Math.max(0, Math.min(100, fuel.stock / fuel.capacity * 100)) : 0;
                    const low = fuel.minimum > 0 && fuel.stock <= fuel.minimum;
                    return (
                        <article className="min-w-0 rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5" key={fuel.fuel}>
                            <div className="flex items-center gap-3">
                                <span className={`grid size-11 shrink-0 place-items-center rounded-lg ${tone.icon}`} aria-hidden="true">
                                    <svg className="size-7" fill="none" stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="1.8" viewBox="0 0 32 32">
                                        <path d="M7 27V6a2 2 0 0 1 2-2h11a2 2 0 0 1 2 2v21M5 27h19M9 8h11v7H9zM22 10h3l3 4v9a2 2 0 0 1-4 0v-5h-2" />
                                    </svg>
                                </span>
                                <div className="min-w-0 flex-1">
                                    <p className={`text-xs font-bold ${tone.value}`}>{fuel.fuel}</p>
                                    <p className="truncate text-xl font-extrabold text-slate-900">{liters(fuel.stock)}</p>
                                </div>
                                <strong className="shrink-0 text-xs text-slate-600">{percent.toFixed(0)}%</strong>
                            </div>
                            <div aria-label={`${fuel.fuel} tank capacity usage`} className="mt-4 h-2 overflow-hidden rounded-full bg-slate-100">
                                <div className={`h-full rounded-full ${tone.bar}`} style={{ width: `${percent}%` }} />
                            </div>
                            <div className="mt-2 flex items-center justify-between gap-2 text-[10px] text-slate-500">
                                <span>{fuel.activeCount} active {fuel.activeCount === 1 ? 'tank' : 'tanks'}</span>
                                <span className={`font-bold ${low ? 'text-rose-700' : 'text-emerald-700'}`}>{low ? 'Low stock' : 'In stock'}</span>
                            </div>
                        </article>
                    );
                })}
                {!fuels.length && <p className="rounded-xl border border-slate-200 bg-white p-5 text-sm text-slate-500">No tank data found for this branch.</p>}
            </div>
            <Panel title="Inventory details" subtitle="Stock and minimum levels are totaled by fuel type for the selected branch">
                <Table headers={['Fuel type', 'Current stock', 'Minimum level', 'Status']}>
                    {fuels.map((fuel) => {
                        const low = fuel.activeCount > 0 && fuel.minimum > 0 && fuel.stock <= fuel.minimum;
                        const status = fuel.activeCount === 0 ? 'Archived' : low ? 'Low stock' : 'In stock';
                        return (
                            <tr key={fuel.fuel}>
                                <td className="px-4 py-3 font-semibold text-slate-900">
                                    {fuel.fuel}
                                    <small className="mt-1 block text-xs font-normal text-slate-500">{fuel.activeCount} active · {fuel.tankCount} total tanks</small>
                                </td>
                                <td className="px-4 py-3 font-semibold">{liters(fuel.stock)}</td>
                                <td className="px-4 py-3">{liters(fuel.minimum)}</td>
                                <td className="px-4 py-3"><Status>{status}</Status></td>
                            </tr>
                        );
                    })}
                    {!fuels.length && <tr><td className="px-4 py-8 text-center text-slate-500" colSpan="4">No inventory records for this branch.</td></tr>}
                </Table>
            </Panel>
            {data.inventory.some((tank) => !tank.is_active) && (
                <p className="mt-3 text-xs text-slate-500">Archived tanks remain included in the stock and capacity totals for audit visibility.</p>
            )}
        </>
    );
}

function Transactions({ data, isOwner }) {
    const [query, setQuery] = useState('');
    const filtered = useMemo(() => data.sales.filter((sale) => `${sale.receipt} ${sale.fuel} ${sale.branch} ${sale.pump} ${sale.nozzle} ${sale.recorder}`.toLowerCase().includes(query.toLowerCase())), [data.sales, query]);
    const mySalesOnly = new URLSearchParams(window.location.search).get('my_sales') === '1';
    const salesFilterUrl = () => {
        const url = new URL(window.location.href);
        if (mySalesOnly) url.searchParams.delete('my_sales');
        else url.searchParams.set('my_sales', '1');
        url.searchParams.delete('sales_page');
        return `${url.pathname}${url.search}${url.hash}`;
    };
    return (
        <>
            <div className="mb-6"><p className="text-[11px] font-extrabold uppercase tracking-widest text-rose-700">Activity ledger</p><h1 className="text-3xl font-extrabold text-slate-900">Transactions</h1><p className="mt-1 text-sm text-slate-500">Imported transactions are marked read-only and cannot be changed or voided.</p></div>
            <Panel allowOverflow title="Sales activity" subtitle={`${filtered.length} records on this page · ${data.pagination.sales.total} total`} action={<div className="flex flex-wrap items-center gap-3"><Input aria-label="Search sales on this page" onChange={(event) => setQuery(event.target.value)} placeholder="Search this page…" value={query} />{!isOwner && <a className="whitespace-nowrap text-xs font-bold text-rose-700 hover:text-rose-900" href={salesFilterUrl()}>{mySalesOnly ? 'Show all branch sales' : 'My sales'}</a>}</div>}>
                <SalesTable sales={filtered} />
                <PageNavigation pagination={data.pagination.sales} parameter="sales_page" />
            </Panel>
        </>
    );
}

function Sales({ data, root }) {
    const activeNozzles = data.pumps.flatMap((pump) => pump.nozzles
        .filter((nozzle) => pump.is_active && nozzle.is_active)
        .map((nozzle) => ({ ...nozzle, branch_id: pump.branch_id, branch: pump.branch, pump: pump.label || pump.code })));
    const branchId = root.dataset.activeBranchId;
    const options = branchId ? activeNozzles.filter((nozzle) => String(nozzle.branch_id) === String(branchId)) : activeNozzles;
    const pricesByBranchProduct = new Map(data.prices.map((price) => [`${price.branch_id}-${price.product_id}`, price.price_per_liter]));
    return (
        <>
            <div className="mb-6"><p className="text-[11px] font-extrabold uppercase tracking-widest text-rose-700">Point of sale</p><h1 className="text-3xl font-extrabold text-slate-900">Record fuel sale</h1><p className="mt-1 text-sm text-slate-500">Sales deduct stock from the selected nozzle’s connected tank.</p></div>
            <div className="grid gap-5 xl:grid-cols-[.8fr_1.2fr]">
                <Panel title="New sale" subtitle="Recorded against the current fuel price">
                    <Form action="/sales" className="grid gap-4 p-5">
                        <BranchField data={data} root={root} />
                        <Field label="Pump nozzle"><Select name="fuel_nozzle_id" required><option value="">Choose a pump/nozzle</option>{options.map((nozzle) => <option key={nozzle.id} value={nozzle.id}>{nozzle.branch} · {nozzle.pump} · {nozzle.code} · {nozzle.fuel} ({money(pricesByBranchProduct.get(`${nozzle.branch_id}-${data.products.find((product) => product.name === nozzle.fuel)?.id}`))}/L)</option>)}</Select></Field>
                        <Field label="Liters"><Input max="100000" min="0.001" name="liters" required step="0.001" type="number" /></Field>
                        <Field label="Receipt number"><Input maxLength="100" name="receipt_number" /></Field>
                        <Field label="Payment method"><Select name="payment_method"><option value="">Not specified</option><option value="cash">Cash</option><option value="card">Card</option><option value="ewallet">E-wallet</option><option value="other">Other</option></Select></Field>
                        <Field label="Sale time"><Input defaultValue={localDateTime()} max={localDateTime()} name="sold_at" required type="datetime-local" /></Field>
                        <ActionButton className="min-h-11 text-sm" type="submit">Record sale</ActionButton>
                    </Form>
                </Panel>
                <Panel allowOverflow title="Recent sales" subtitle="Database records · imported sales are immutable">
                    <SalesTable sales={data.sales.slice(0, 15)} />
                    <PageNavigation pagination={data.pagination.sales} parameter="sales_page" />
                </Panel>
            </div>
        </>
    );
}

function Deliveries({ data, root }) {
    const [editingDeliveryId, setEditingDeliveryId] = useState(null);
    const editingDelivery = data.deliveries.find((delivery) => delivery.id === editingDeliveryId);
    const availableTanks = data.inventory.filter((tank) => tank.is_active && (!root.dataset.activeBranchId || String(tank.branch_id) === root.dataset.activeBranchId));
    return (
        <>
            <div className="mb-6"><p className="text-[11px] font-extrabold uppercase tracking-widest text-rose-700">Stock-in</p><h1 className="text-3xl font-extrabold text-slate-900">Fuel deliveries</h1><p className="mt-1 text-sm text-slate-500">Pending deliveries can be edited or archived; approved volumes remain in the audit ledger.</p></div>
            <div className="mb-5 grid gap-5 2xl:grid-cols-[.8fr_1.2fr]">
                <Panel title="Record delivery" subtitle="Stock is added after approval">
                    <Form action="/deliveries" className="grid gap-4 p-5" encType="multipart/form-data">
                        <BranchField data={data} root={root} />
                        <Field label="Receiving tank"><Select name="fuel_tank_id" required><option value="">Choose tank</option>{availableTanks.map((tank) => <option key={tank.id} value={tank.id}>{tank.branch} · {tank.code} · {tank.fuel}</option>)}</Select></Field>
                        <div className="grid gap-3 sm:grid-cols-2"><Field label="Delivered liters"><Input min="0.001" name="delivered_liters" required step="0.001" type="number" /></Field><Field label="Cost per liter"><Input min="0" name="cost_per_liter" required step="0.01" type="number" /></Field></div>
                        <div className="grid gap-3 sm:grid-cols-2"><Field label="Supplier"><Input maxLength="255" name="supplier_name" /></Field><Field label="Reference"><Input maxLength="100" name="delivery_reference" /></Field></div>
                        <Field label="Received at"><Input defaultValue={localDateTime()} max={localDateTime()} name="received_at" required type="datetime-local" /></Field>
                        <Field label="Invoice (optional)"><Input accept=".pdf,.jpg,.jpeg,.png" name="invoice" type="file" /></Field>
                        <ActionButton className="min-h-11 text-sm" type="submit">Submit delivery</ActionButton>
                    </Form>
                </Panel>
                <Panel title="Delivery records" subtitle={`${data.deliveries.length} latest records`}>
                    <Table headers={['Branch · Tank', 'Supplier / reference', 'Received', 'Liters', 'Cost', 'Status', 'Actions']}>
                        {data.deliveries.map((delivery) => (
                            <React.Fragment key={delivery.id}>
                                <tr>
                                    <td className="px-4 py-3 align-middle">{delivery.branch} · {delivery.tank} ({delivery.fuel})</td>
                                    <td className="px-4 py-3 align-middle">{delivery.supplier || '—'} / {delivery.reference || '—'}</td>
                                    <td className="whitespace-nowrap px-4 py-3 align-middle">{dateTime(delivery.received_at)}</td>
                                    <td className="px-4 py-3 align-middle">{liters(delivery.liters)}</td>
                                    <td className="px-4 py-3 align-middle">{money(delivery.total_cost)}</td>
                                    <td className="px-4 py-3 align-middle"><Status>{delivery.status}</Status></td>
                                    <td className="px-4 py-3 align-middle">
                                        <div className="flex items-center gap-2">
                                            {delivery.status === 'pending' && delivery.can_edit && <button aria-label="Edit delivery" className="grid size-9 place-items-center rounded-lg border border-violet-200 text-violet-600 transition hover:bg-violet-50" onClick={() => setEditingDeliveryId(delivery.id)} title="Edit" type="button">
                                                <svg aria-hidden="true" className="size-4" fill="none" stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="1.8" viewBox="0 0 24 24"><path d="m15 5 4 4M4 20l4-.8L19 8a2.1 2.1 0 0 0-3-3L5 16l-1 4Z" /></svg>
                                            </button>}
                                            {delivery.status === 'pending' && <Form action={`/deliveries/${delivery.id}/approve`}><button aria-label="Approve delivery" className="grid size-9 place-items-center rounded-lg border border-emerald-200 text-emerald-700 transition hover:bg-emerald-50" title="Approve" type="submit"><svg aria-hidden="true" className="size-4" fill="none" stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" viewBox="0 0 24 24"><path d="m5 12 4 4L19 6" /></svg></button></Form>}
                                            {delivery.status === 'pending' && <Form action={`/deliveries/${delivery.id}/reject`}><input name="review_notes" type="hidden" value="Archived by the reviewer from delivery records." /><button aria-label="Archive delivery" className="grid size-9 place-items-center rounded-lg border border-rose-200 text-rose-600 transition hover:bg-rose-50" title="Archive" type="submit"><svg aria-hidden="true" className="size-4" fill="none" stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="1.8" viewBox="0 0 24 24"><path d="M3 6h18M8 6V4h8v2m3 0-1 14H6L5 6m4 4v6m6-6v6" /></svg></button></Form>}
                                            {delivery.can_reverse && <Form action={`/deliveries/${delivery.id}/void`} className="flex flex-wrap items-center gap-2"><Input className="w-40" name="void_reason" placeholder="Reversal reason" required /><ActionButton type="submit">Reverse</ActionButton></Form>}
                                        </div>
                                    </td>
                                </tr>
                            </React.Fragment>
                        ))}
                        {!data.deliveries.length && <tr><td className="px-4 py-8 text-center text-slate-500" colSpan="7">No deliveries recorded.</td></tr>}
                    </Table>
                    <PageNavigation pagination={data.pagination.deliveries} parameter="deliveries_page" />
                </Panel>
            </div>
            {editingDelivery && editingDelivery.can_edit && <EditModal onClose={() => setEditingDeliveryId(null)} title={`Edit delivery · ${editingDelivery.tank}`}>
                <Form action={`/deliveries/${editingDelivery.id}`} className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3" method="PUT">
                    <Field label="Tank"><Select defaultValue={editingDelivery.tank_id} name="fuel_tank_id" required>{availableTanks.filter((tank) => tank.branch_id === editingDelivery.branch_id).map((tank) => <option key={tank.id} value={tank.id}>{tank.code} · {tank.fuel}</option>)}</Select></Field>
                    <Field label="Liters"><Input defaultValue={editingDelivery.liters} min="0.001" name="delivered_liters" required step="0.001" type="number" /></Field>
                    <Field label="Cost per liter"><Input defaultValue={editingDelivery.cost_per_liter} min="0" name="cost_per_liter" required step="0.01" type="number" /></Field>
                    <Field label="Supplier"><Input defaultValue={editingDelivery.supplier || ''} name="supplier_name" /></Field>
                    <Field label="Reference"><Input defaultValue={editingDelivery.reference || ''} name="delivery_reference" /></Field>
                    <Field label="Received at"><Input defaultValue={localDateTime()} name="received_at" required type="datetime-local" /></Field>
                    <div className="flex items-end gap-2 sm:col-span-2 xl:col-span-3"><ActionButton type="submit">Save pending delivery</ActionButton></div>
                </Form>
            </EditModal>}
        </>
    );
}

function Adjustments({ data, root }) {
    const [adjustmentDialog, setAdjustmentDialog] = useState(null);
    const activeAdjustment = data.adjustments.find((adjustment) => adjustment.id === adjustmentDialog?.id);
    const tanks = data.inventory.filter((tank) => tank.is_active && (!root.dataset.activeBranchId || String(tank.branch_id) === root.dataset.activeBranchId));
    return (
        <>
            <div className="mb-6"><p className="text-[11px] font-extrabold uppercase tracking-widest text-rose-700">Variance control</p><h1 className="text-3xl font-extrabold text-slate-900">Stock adjustments</h1><p className="mt-1 text-sm text-slate-500">All posted stock changes remain auditable.</p></div>
            <div className="mb-5 grid gap-5 xl:grid-cols-[.75fr_1.25fr]">
                <Panel title="Request adjustment" subtitle="Positive adds stock; negative removes stock">
                    <Form action="/adjustments" className="grid gap-4 p-5">
                        <BranchField data={data} root={root} />
                        <Field label="Tank"><Select name="fuel_tank_id" required><option value="">Choose tank</option>{tanks.map((tank) => <option key={tank.id} value={tank.id}>{tank.branch} · {tank.code} · {tank.fuel} · {liters(tank.stock_liters)} on hand</option>)}</Select></Field>
                        <Field label="Quantity change (L)"><Input name="quantity_change_liters" required step="0.001" type="number" /></Field>
                        <Field label="Reason"><Input name="reason" required /></Field>
                        <ActionButton className="min-h-11 text-sm" type="submit">Submit for review</ActionButton>
                    </Form>
                </Panel>
                <Panel allowOverflow title="Adjustment history" subtitle={`${data.adjustments.length} latest records`}>
                    <Table headers={['Branch · Tank', 'Change', 'Reason', 'Requested by', 'Status', 'Actions']}>
                        {data.adjustments.map((adjustment) => <tr key={adjustment.id}>
                            <td className="px-4 py-3">{adjustment.branch} · {adjustment.tank} ({adjustment.fuel})</td>
                            <td className="px-4 py-3">{liters(adjustment.quantity_change_liters)}</td>
                            <td className="px-4 py-3">{adjustment.reason}</td>
                            <td className="px-4 py-3">{adjustment.requester}</td>
                            <td className="px-4 py-3"><Status>{adjustment.status}</Status></td>
                            <td className="px-4 py-3">
                                <div className="flex items-center gap-2">
                                    {adjustment.status === 'pending' && adjustment.can_edit && <button aria-label="Edit adjustment" className="grid size-9 place-items-center rounded-lg border border-violet-200 text-violet-600 transition hover:bg-violet-50" onClick={() => setAdjustmentDialog({ id: adjustment.id, action: 'edit' })} title="Edit" type="button">
                                        <svg aria-hidden="true" className="size-4" fill="none" stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="1.8" viewBox="0 0 24 24"><path d="m15 5 4 4M4 20l4-.8L19 8a2.1 2.1 0 0 0-3-3L5 16l-1 4Z" /></svg>
                                    </button>}
                                    {adjustment.status === 'pending' && <Form action={`/adjustments/${adjustment.id}/approve`}><button aria-label="Approve adjustment" className="grid size-9 place-items-center rounded-lg border border-emerald-200 text-emerald-700 transition hover:bg-emerald-50" title="Approve" type="submit"><svg aria-hidden="true" className="size-4" fill="none" stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" viewBox="0 0 24 24"><path d="m5 12 4 4L19 6" /></svg></button></Form>}
                                    {adjustment.status === 'pending' && <Form action={`/adjustments/${adjustment.id}/reject`}><input name="review_notes" type="hidden" value="Archived by the reviewer from adjustment history." /><button aria-label="Archive adjustment" className="grid size-9 place-items-center rounded-lg border border-rose-200 text-rose-600 transition hover:bg-rose-50" title="Archive" type="submit"><svg aria-hidden="true" className="size-4" fill="none" stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="1.8" viewBox="0 0 24 24"><path d="M3 6h18M8 6V4h8v2m3 0-1 14H6L5 6m4 4v6m6-6v6" /></svg></button></Form>}
                                    {adjustment.can_reverse && <button aria-label="Reverse adjustment" className="grid size-9 place-items-center rounded-lg border border-amber-200 text-amber-700 transition hover:bg-amber-50" onClick={() => setAdjustmentDialog({ id: adjustment.id, action: 'reverse' })} title="Reverse" type="button"><svg aria-hidden="true" className="size-4" fill="none" stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="1.8" viewBox="0 0 24 24"><path d="M3 12a9 9 0 1 0 3-6.7L3 8" /><path d="M3 3v5h5" /></svg></button>}
                                </div>
                            </td>
                        </tr>)}
                        {!data.adjustments.length && <tr><td className="px-4 py-8 text-center text-slate-500" colSpan="6">No adjustments recorded.</td></tr>}
                    </Table>
                    <PageNavigation pagination={data.pagination.adjustments} parameter="adjustments_page" />
                </Panel>
            </div>
            {activeAdjustment && adjustmentDialog?.action === 'edit' && activeAdjustment.can_edit && <EditModal onClose={() => setAdjustmentDialog(null)} title={`Edit adjustment · ${activeAdjustment.tank}`}>
                <Form action={`/adjustments/${activeAdjustment.id}`} className="grid gap-4 sm:grid-cols-2" method="PUT">
                    <Field label="Tank"><Select defaultValue={activeAdjustment.tank_id} name="fuel_tank_id" required>{tanks.filter((tank) => tank.branch_id === activeAdjustment.branch_id).map((tank) => <option key={tank.id} value={tank.id}>{tank.code} · {tank.fuel}</option>)}</Select></Field>
                    <Field label="Quantity change (L)"><Input defaultValue={activeAdjustment.quantity_change_liters} name="quantity_change_liters" required step="0.001" type="number" /></Field>
                    <Field label="Reason"><Input defaultValue={activeAdjustment.reason} name="reason" required /></Field>
                    <div className="flex items-end"><ActionButton type="submit">Save pending adjustment</ActionButton></div>
                </Form>
            </EditModal>}
            {activeAdjustment && adjustmentDialog?.action === 'reverse' && activeAdjustment.can_reverse && <EditModal onClose={() => setAdjustmentDialog(null)} title={`Reverse adjustment · ${activeAdjustment.tank}`}>
                <Form action={`/adjustments/${activeAdjustment.id}/void`} className="grid gap-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
                    <Field label="Reversal reason"><Input name="void_reason" required /></Field>
                    <ActionButton type="submit">Reverse posted adjustment</ActionButton>
                </Form>
            </EditModal>}
        </>
    );
}

function Prices({ data, root }) {
    const effectiveFromChanged = useRef(false);
    const activeProducts = data.products.filter((product) => product.is_active);
    const branchPrices = [...data.prices].sort((left, right) => compareFuelNames(left.fuel, right.fuel));
    const priceTone = (index) => [
        'bg-emerald-50 text-emerald-800',
        'bg-rose-50 text-rose-800',
        'bg-amber-50 text-amber-800',
    ][index % 3];
    return (
        <>
            <div className="mb-6">
                <p className="text-[11px] font-extrabold uppercase tracking-widest text-rose-700">Price management</p>
                <h1 className="text-3xl font-extrabold text-slate-900">Fuel prices</h1>
                <p className="mt-1 text-sm text-slate-500">Current branch prices · updates are recorded in the price audit history.</p>
            </div>
            <div className="mb-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                {branchPrices.map((price, index) => (
                    <article className="flex min-w-0 items-center gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm" key={price.id}>
                        <span className={`grid size-11 shrink-0 place-items-center rounded-lg ${priceTone(index)}`} aria-hidden="true">
                            <svg className="size-7" fill="none" stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="1.8" viewBox="0 0 32 32">
                                <path d="M7 27V6a2 2 0 0 1 2-2h11a2 2 0 0 1 2 2v21M5 27h19M9 8h11v7H9zM22 10h3l3 4v9a2 2 0 0 1-4 0v-5h-2" />
                            </svg>
                        </span>
                        <div className="min-w-0 flex-1">
                            <p className="text-xs font-bold">{price.fuel}</p>
                            <p className="text-xl font-extrabold text-slate-900">{money(price.price_per_liter)}<span className="ml-1 text-xs font-semibold text-slate-500">/ L</span></p>
                            <p className="truncate text-[10px] text-slate-500">Effective {dateTime(price.effective_from)}</p>
                        </div>
                    </article>
                ))}
                {!branchPrices.length && <p className="rounded-xl border border-slate-200 bg-white p-5 text-sm text-slate-500">No current fuel prices configured for this branch.</p>}
            </div>
            <div className="mb-5 grid min-w-0 items-start gap-5 xl:grid-cols-[minmax(0,1.5fr)_minmax(280px,.8fr)]">
                <Panel allowOverflow title="Current price list" subtitle="Current effective price by fuel; previous prices remain in the audit history">
                    <Table headers={['Fuel', 'Price per liter', 'Effective from', 'Set by']}>
                        {branchPrices.map((price) => <tr key={price.id}>
                            <td className="px-4 py-3 font-semibold">{price.fuel}</td>
                            <td className="px-4 py-3 font-bold">{money(price.price_per_liter)}</td>
                            <td className="px-4 py-3 whitespace-nowrap">{dateTime(price.effective_from)}</td>
                            <td className="px-4 py-3">{price.setter}</td>
                        </tr>)}
                        {!branchPrices.length && <tr><td className="px-4 py-8 text-center text-slate-500" colSpan="4">No current prices for this branch.</td></tr>}
                    </Table>
                </Panel>
                <Panel title="Update fuel prices" subtitle="Creates a new effective price record">
                    <Form action="/setup/prices" className="grid gap-3 p-4 sm:p-5" onSubmit={(event) => {
                        if (!effectiveFromChanged.current) {
                            const effectiveFromInput = event.currentTarget.elements.namedItem('effective_from');
                            if (effectiveFromInput instanceof HTMLInputElement) {
                                effectiveFromInput.value = localDateTime();
                            }
                        }
                    }}>
                        <BranchField data={data} root={root} />
                        <Field label="Fuel"><Select name="fuel_product_id" required>{activeProducts.map((product) => <option key={product.id} value={product.id}>{product.name}</option>)}</Select></Field>
                        <Field label="Price per liter"><Input min="0.01" name="price_per_liter" required step="0.01" type="number" /></Field>
                        <Field label="Effective from"><Input defaultValue={localDateTime()} name="effective_from" onChange={() => { effectiveFromChanged.current = true; }} required type="datetime-local" /></Field>
                        <ActionButton className="min-h-10" type="submit">Add effective price</ActionButton>
                    </Form>
                </Panel>
            </div>
        </>
    );
}

function Reports({ data, root }) {
    const chartContainerRef = useRef(null);
    const formatDate = (date) => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
    const today = new Date();
    const initialTo = formatDate(today);
    const initialFromDate = new Date(today);
    initialFromDate.setDate(initialFromDate.getDate() - 6);
    const [reportType, setReportType] = useState('liters');
    const [fuelProductId, setFuelProductId] = useState('all');
    const [fromInput, setFromInput] = useState(() => formatDate(initialFromDate));
    const [toInput, setToInput] = useState(() => initialTo);
    const [fromDate, setFromDate] = useState(() => formatDate(initialFromDate));
    const [toDate, setToDate] = useState(() => initialTo);
    const [report, setReport] = useState(null);
    const [error, setError] = useState('');
    const [hoveredPoint, setHoveredPoint] = useState(null);

    useEffect(() => {
        let cancelled = false;
        const query = new URLSearchParams({
            from: fromDate,
            to: toDate,
            branch_id: root.dataset.activeBranchId,
        });
        requestJson(`${root.dataset.reportsSummaryUrl}?${query}`)
            .then((response) => {
                if (!cancelled) {
                    setReport(response);
                    setError('');
                }
            })
            .catch((requestError) => {
                if (!cancelled) setError(requestError.message);
            });
        return () => { cancelled = true; };
    }, [fromDate, root, toDate]);

    const sales = report?.sales;
    const isOwner = root.dataset.isOwner === 'true';
    const days = useMemo(() => {
        const result = [];
        if (!fromDate || !toDate || fromDate > toDate) return result;
        const cursor = new Date(`${fromDate}T12:00:00`);
        const end = new Date(`${toDate}T12:00:00`);
        while (cursor <= end && result.length < 367) {
            result.push(formatDate(cursor));
            cursor.setDate(cursor.getDate() + 1);
        }
        return result;
    }, [fromDate, toDate]);
    const productsWithSales = new Set((report?.sales_by_fuel ?? []).map((entry) => String(entry.fuel_product_id)));
    const fuelProducts = data.products.filter((product) => product.is_active || productsWithSales.has(String(product.id)));
    const selectedProducts = fuelProductId === 'all'
        ? fuelProducts
        : fuelProducts.filter((product) => String(product.id) === fuelProductId);
    const dailyFuelSales = report?.sales_by_day_and_fuel ?? [];
    const productTotals = report?.sales_by_fuel ?? [];
    const visibleSales = fuelProductId === 'all'
        ? sales
        : productTotals.find((entry) => String(entry.fuel_product_id) === fuelProductId) ?? (report ? { sales_amount: 0, liters: 0, transaction_count: 0 } : null);
    const chartColors = ['#22c55e', '#ef4444', '#fbbf24', '#3b82f6', '#a855f7', '#f97316'];
    const chartSeries = selectedProducts.map((product, index) => {
        const points = days.map((day) => {
            const entry = dailyFuelSales.find((item) => item.date === day && String(item.fuel_product_id) === String(product.id));
            return { date: day, entry, value: entry?.[reportType] ?? 0 };
        });
        return { ...product, color: chartColors[index % chartColors.length], points, values: points.map((point) => point.value) };
    });
    const chartMaximum = Math.max(0, ...chartSeries.flatMap((series) => series.values));
    const chartStep = chartMaximum > 0 ? Math.pow(10, Math.floor(Math.log10(chartMaximum)) - 1) : 1;
    const chartTop = chartMaximum > 0 ? Math.ceil(chartMaximum / chartStep) * chartStep : 4;
    const chartHeight = 280;
    const chartWidth = 900;
    const chartLeft = 76;
    const chartRight = 20;
    const chartTopPadding = 24;
    const chartBottom = chartTopPadding + chartHeight;
    const xPosition = (index) => chartLeft + (days.length > 1 ? index / (days.length - 1) : 0.5) * (chartWidth - chartLeft - chartRight);
    const yPosition = (value) => chartBottom - (value / chartTop) * chartHeight;
    const xLabelIndexes = [...new Set([0, Math.floor((days.length - 1) / 4), Math.floor((days.length - 1) / 2), Math.floor((days.length - 1) * 3 / 4), days.length - 1])];
    const dateLabel = (day) => new Intl.DateTimeFormat('en-PH', { month: 'short', day: 'numeric' }).format(new Date(`${day}T12:00:00`));
    const metricLabel = reportType === 'liters' ? 'Liters sold' : 'Sales revenue';
    const reportLiters = (value) => `${Number(value ?? 0).toLocaleString('en-PH', { maximumFractionDigits: 3 })} ${Number(value) === 1 ? 'Liter' : 'Liters'}`;
    const exportUrl = new URL(root.dataset.reportsSalesCsvUrl, window.location.origin);
    exportUrl.searchParams.set('from', fromDate);
    exportUrl.searchParams.set('to', toDate);
    exportUrl.searchParams.set('branch_id', root.dataset.activeBranchId);
    const invalidRange = !fromInput || !toInput || fromInput > toInput || fromInput > initialTo || toInput > initialTo || (new Date(`${toInput}T12:00:00`) - new Date(`${fromInput}T12:00:00`)) / 86400000 > 366;
    const generateReport = (event) => {
        event.preventDefault();
        if (invalidRange) return;
        setFromDate(fromInput);
        setToDate(toInput);
    };

    return (
        <>
            <div className="mb-6"><p className="text-[11px] font-extrabold uppercase tracking-widest text-rose-700">Operations report</p><h1 className="text-3xl font-extrabold text-slate-900">Sales Reports</h1><p className="mt-1 text-sm text-slate-500">Generate and view fuel sales reports for the selected branch.</p></div>
            <form aria-label="Sales report filters" className="report-filters mb-5 grid items-end gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm md:grid-cols-2 xl:grid-cols-[1fr_1fr_1fr_1fr_auto]" onSubmit={generateReport}>
                <Field label="Report type"><Select onChange={(event) => setReportType(event.target.value)} value={reportType}><option value="liters">Sales volume (liters)</option><option value="revenue">Sales revenue (₱)</option></Select></Field>
                <Field label="Fuel type"><Select onChange={(event) => setFuelProductId(event.target.value)} value={fuelProductId}><option value="all">All fuel types</option>{fuelProducts.map((product) => <option key={product.id} value={product.id}>{product.name}</option>)}</Select></Field>
                <Field label="Start date"><Input max={toInput || initialTo} onChange={(event) => setFromInput(event.target.value)} type="date" value={fromInput} /></Field>
                <Field label="End date"><Input max={initialTo} min={fromInput} onChange={(event) => setToInput(event.target.value)} type="date" value={toInput} /></Field>
                <ActionButton className="min-h-10 md:col-span-2 xl:col-span-1" disabled={invalidRange} type="submit">Generate report</ActionButton>
            </form>
            {error && <div className="mb-5 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800" role="alert">Could not load sales statistics: {error}</div>}
            <div className="report-layout grid min-w-0 items-start gap-4 xl:grid-cols-[minmax(0,1fr)_220px]">
                <Panel title="Sales Summary" subtitle={`${dateLabel(fromDate)} – ${dateLabel(toDate)} · ${metricLabel.toLowerCase()}`}>
                    <div aria-label={`Daily ${metricLabel.toLowerCase()} by fuel type`} className="relative overflow-x-auto px-3 pb-3 pt-5 sm:px-5" ref={chartContainerRef} role="img">
                        <svg className="sales-report-chart" role="presentation" viewBox={`0 0 ${chartWidth} 370`} xmlns="http://www.w3.org/2000/svg">
                            {[0, 1, 2, 3, 4].map((tick) => {
                                const value = chartTop * tick / 4;
                                const y = yPosition(value);
                                const formattedValue = Math.round(value).toLocaleString('en-PH');
                                const label = reportType === 'liters' ? (value === 0 ? '0 Liters' : `${formattedValue} L`) : `₱${formattedValue}`;
                                return <g key={tick}><line stroke="#d1d5db" strokeWidth="1" x1={chartLeft} x2={chartWidth - chartRight} y1={y} y2={y} /><text fill="#6b7280" fontSize="13" textAnchor="end" x={chartLeft - 10} y={y + 4}>{label}</text></g>;
                            })}
                            {xLabelIndexes.map((index) => <g key={`${days[index]}-${index}`}><line stroke="#d1d5db" strokeWidth="1" x1={xPosition(index)} x2={xPosition(index)} y1={chartTopPadding} y2={chartBottom} /><text fill="#6b7280" fontSize="13" textAnchor="middle" x={xPosition(index)} y={chartBottom + 25}>{dateLabel(days[index])}</text></g>)}
                            {chartSeries.map((series) => {
                                const points = series.values.map((value, index) => `${index === 0 ? 'M' : 'L'} ${xPosition(index)} ${yPosition(value)}`).join(' ');
                                return <g key={series.id}><path d={points} fill="none" stroke={series.color} strokeWidth="2" />{series.points.map((point, index) => {
                                    const tooltipText = `${series.name} · ${dateLabel(point.date)}: ${reportLiters(point.entry?.liters ?? 0)}, ${money(point.entry?.revenue ?? 0)}, ${point.entry?.transaction_count ?? 0} transactions`;
                                    const showPointTooltip = (event) => {
                                        if (!isOwner || !chartContainerRef.current) return;
                                        const bounds = chartContainerRef.current.getBoundingClientRect();
                                        setHoveredPoint({
                                            name: series.name,
                                            date: dateLabel(point.date),
                                            liters: reportLiters(point.entry?.liters ?? 0),
                                            revenue: money(point.entry?.revenue ?? 0),
                                            transactions: point.entry?.transaction_count ?? 0,
                                            left: Math.max(8, Math.min(bounds.width - 228, event.clientX - bounds.left + 12)),
                                            top: Math.max(8, Math.min(bounds.height - 118, event.clientY - bounds.top + 12)),
                                            color: series.color,
                                        });
                                    };
                                    return <circle cx={xPosition(index)} cy={yPosition(point.value)} fill={series.color} key={`${series.id}-${point.date}`} onMouseEnter={showPointTooltip} onMouseMove={showPointTooltip} onMouseLeave={() => setHoveredPoint(null)} r="5"><title>{tooltipText}</title></circle>;
                                })}</g>;
                            })}
                            {days.length === 0 && <text fill="#64748b" fontSize="16" textAnchor="middle" x={chartWidth / 2} y={chartBottom / 2}>Choose a valid date range to view the report.</text>}
                        </svg>
                        {isOwner && hoveredPoint && <div className="pointer-events-none absolute z-10 w-56 rounded-lg border border-slate-200 bg-white p-3 text-xs shadow-lg" style={{ left: `${hoveredPoint.left}px`, top: `${hoveredPoint.top}px` }}>
                            <p className="mb-2 flex items-center gap-2 font-bold text-slate-900"><i className="size-2.5 rounded-full" style={{ backgroundColor: hoveredPoint.color }} />{hoveredPoint.name} · {hoveredPoint.date}</p>
                            <p className="text-slate-600">Sales details</p>
                            <p className="mt-1 text-slate-700">{hoveredPoint.liters} · {hoveredPoint.revenue}</p>
                            <p className="text-slate-600">{hoveredPoint.transactions} transactions</p>
                        </div>}
                    </div>
                    <div className="flex flex-wrap gap-x-6 gap-y-2 border-t border-slate-100 px-5 py-4 text-sm text-slate-600">
                        {chartSeries.map((series) => <span className="flex items-center gap-2" key={series.id}><i className="size-2.5 rounded-full" style={{ backgroundColor: series.color }} />{series.name}</span>)}
                        {!report && !error && <span role="status">Loading sales data…</span>}
                        {report && !dailyFuelSales.length && <span>No sales recorded for this period.</span>}
                    </div>
                </Panel>
                <Panel title="Report Actions">
                    <div className="grid gap-3 p-4">
                        <div className="rounded-xl bg-slate-50 p-3">
                            <p className="text-xs font-semibold text-slate-500">{fuelProductId === 'all' ? 'Total sales' : `${selectedProducts[0]?.name ?? 'Fuel'} sales`}</p>
                            <p className="mt-1 text-lg font-extrabold text-slate-900">{visibleSales ? money(visibleSales.sales_amount ?? visibleSales.revenue) : '—'}</p>
                            <p className="text-xs text-slate-600">{visibleSales ? reportLiters(visibleSales.liters) : '—'} · {visibleSales?.transaction_count ?? sales?.transaction_count ?? '—'} transactions</p>
                        </div>
                        <a className="flex min-h-10 items-center justify-center gap-2 rounded-lg border border-blue-300 bg-white px-3 py-2 text-sm font-bold text-blue-700 transition hover:bg-blue-50" href={exportUrl.toString()}>
                            <svg aria-hidden="true" className="size-4" fill="none" stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="1.8" viewBox="0 0 24 24"><path d="M12 3v12m0 0 4-4m-4 4-4-4M5 17v4h14v-4" /></svg>
                            Download CSV
                        </a>
                    </div>
                </Panel>
            </div>
            <div className="mt-5 grid gap-4 sm:grid-cols-3">
                <article className="rounded-2xl border border-slate-200 bg-white p-4"><p className="text-sm font-semibold text-slate-500">Gross sales</p><p className="mt-1 text-xl font-extrabold text-slate-900">{sales ? money(sales.revenue) : '—'}</p></article>
                <article className="rounded-2xl border border-slate-200 bg-white p-4"><p className="text-sm font-semibold text-slate-500">Liters sold</p><p className="mt-1 text-xl font-extrabold text-slate-900">{sales ? liters(sales.liters) : '—'}</p></article>
                <article className="rounded-2xl border border-slate-200 bg-white p-4"><p className="text-sm font-semibold text-slate-500">Transactions</p><p className="mt-1 text-xl font-extrabold text-slate-900">{sales?.transaction_count ?? '—'}</p></article>
            </div>
            <div className="mt-5"><Panel title="Inventory balances"><Table headers={['Branch', 'Tank', 'Fuel', 'Stock', 'Capacity']}>{data.inventory.filter((tank) => fuelProductId === 'all' || String(tank.fuel_product_id) === fuelProductId).map((tank) => <tr key={tank.id}><td className="px-4 py-3">{tank.branch}</td><td className="px-4 py-3">{tank.code}</td><td className="px-4 py-3">{tank.fuel}</td><td className="px-4 py-3">{liters(tank.stock_liters)}</td><td className="px-4 py-3">{liters(tank.capacity_liters)}</td></tr>)}</Table></Panel></div>
        </>
    );
}

function Users({ data, root }) {
    const editableUsers = data.users;
    return (
        <>
            <div className="mb-6"><p className="text-[11px] font-extrabold uppercase tracking-widest text-rose-700">Access management</p><h1 className="text-3xl font-extrabold text-slate-900">Team management</h1><p className="mt-1 text-sm text-slate-500">Staff accounts can be edited or deactivated; accounts are not physically deleted.</p></div>
            <div className="mb-5">
                <Panel title="Add staff member">
                    <Form action="/users" className="grid gap-3 p-5 md:grid-cols-3">
                        <BranchField data={data} root={root} />
                        <Field label="Name"><Input maxLength="255" name="name" required /></Field>
                        <Field label="Email"><Input name="email" required type="email" /></Field>
                        <input name="role" type="hidden" value="staff" />
                        <Field label="Password"><Input minLength="10" name="password" required type="password" /></Field>
                        <Field label="Confirm password"><Input minLength="10" name="password_confirmation" required type="password" /></Field>
                        <div className="flex items-end"><ActionButton className="min-h-10" type="submit">Create account</ActionButton></div>
                    </Form>
                </Panel>
            </div>
            <Panel title="Staff accounts" subtitle={`${editableUsers.length} accounts in this branch scope`}>
                <div className="grid gap-3 p-5">
                    {editableUsers.map((member) => <details className="rounded-xl border border-slate-200 p-4" key={member.id}>
                        <summary className="flex cursor-pointer flex-wrap items-center justify-between gap-3">
                            <span><strong className="text-slate-900">{member.name}</strong><small className="ml-2 text-slate-500">{member.email} · {member.branch}</small></span><Status>{member.is_active ? 'Active' : 'Inactive'}</Status>
                        </summary>
                        <div className="mt-4 grid gap-4 md:grid-cols-2">
                            <Form action={`/users/${member.id}`} className="grid gap-3" method="PUT">
                                <Field label="Name"><Input defaultValue={member.name} name="name" required /></Field>
                                <Field label="Email"><Input defaultValue={member.email} name="email" required type="email" /></Field>
                                <Field label="Branch"><Select defaultValue={member.branch_id} name="branch_id" required>{data.branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}</Select></Field>
                                <input name="role" type="hidden" value="staff" />
                                <Field label="New password (optional)"><Input name="password" type="password" /></Field>
                                <Field label="Confirm password"><Input name="password_confirmation" type="password" /></Field>
                                <ActionButton type="submit">Save staff changes</ActionButton>
                            </Form>
                            <Form action={`/users/${member.id}/status`} className="flex items-end" method="PATCH">
                                <input name="is_active" type="hidden" value={member.is_active ? '0' : '1'} />
                                <ActionButton type="submit">{member.is_active ? 'Deactivate account' : 'Reactivate account'}</ActionButton>
                            </Form>
                        </div>
                    </details>)}
                    {!editableUsers.length && <p className="py-6 text-center text-sm text-slate-500">No staff accounts found.</p>}
                </div>
            </Panel>
        </>
    );
}

export default function WorkspacePage({ page, data, root, userName }) {
    switch (page) {
        case 'dashboard': return <Dashboard data={data} userName={userName} />;
        case 'inventory': return <Inventory data={data} isOwner={root.dataset.isOwner === 'true'} />;
        case 'sales': return <Sales data={data} root={root} />;
        case 'transactions': return <Transactions data={data} isOwner={root.dataset.isOwner === 'true'} />;
        case 'deliveries': return <Deliveries data={data} root={root} />;
        case 'adjustments': return <Adjustments data={data} root={root} />;
        case 'prices': return <Prices data={data} root={root} />;
        case 'reports': return <Reports data={data} root={root} />;
        case 'users': return <Users data={data} root={root} />;
        default: return <Dashboard data={data} userName={userName} />;
    }
}

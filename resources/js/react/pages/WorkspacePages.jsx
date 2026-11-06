import React, { useMemo, useState } from 'react';

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';
const money = (value) => `₱${Number(value ?? 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const liters = (value) => `${Number(value ?? 0).toLocaleString('en-PH', { maximumFractionDigits: 3 })} L`;
const dateTime = (value) => value
    ? new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
    : '—';
const localDateTime = () => {
    const now = new Date();
    return new Date(now.getTime() - now.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
};

function ActionButton({ children, className = '', ...props }) {
    return <button className={`inline-flex min-h-9 items-center justify-center rounded-xl bg-gradient-to-r from-rose-600 to-blue-800 px-3 py-2 text-xs font-bold text-white shadow-sm disabled:opacity-60 ${className}`} {...props}>{children}</button>;
}

function Panel({ title, children, subtitle, action }) {
    return (
        <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <header className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-4">
                <div><h2 className="font-bold text-slate-900">{title}</h2>{subtitle && <p className="mt-1 text-xs text-slate-500">{subtitle}</p>}</div>
                {action}
            </header>
            {children}
        </section>
    );
}

function Field({ label, children }) {
    return <label className="grid gap-1.5 text-sm font-semibold text-slate-700">{label}{children}</label>;
}

function Input(props) {
    return <input className="min-h-10 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm text-slate-800 outline-none focus:border-rose-400 focus:ring-4 focus:ring-rose-100" {...props} />;
}

function Select({ children, ...props }) {
    return <select className="min-h-10 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800 outline-none focus:border-rose-400 focus:ring-4 focus:ring-rose-100" {...props}>{children}</select>;
}

function Form({ action, method = 'POST', children, className = '', encType }) {
    return (
        <form action={action} className={className} encType={encType} method="POST">
            <input name="_token" type="hidden" value={csrf()} />
            {method !== 'POST' && <input name="_method" type="hidden" value={method} />}
            {children}
        </form>
    );
}

function Table({ headers, children }) {
    return (
        <div className="overflow-x-auto">
            <table className="w-full min-w-[760px] text-left text-sm">
                <thead className="bg-slate-50 text-[10px] font-extrabold uppercase tracking-wider text-slate-500"><tr>{headers.map((header) => <th className="px-4 py-3" key={header}>{header}</th>)}</tr></thead>
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
    return (
        <Field label="Branch">
            <Select defaultValue={root.dataset.activeBranchId || ''} name="branch_id" required>
                <option value="">Choose branch</option>
                {data.branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
            </Select>
        </Field>
    );
}

function SalesTable({ sales }) {
    return (
        <Table headers={['Receipt', 'Fuel', 'Branch · pump/nozzle', 'Date & time', 'Liters', 'Amount', 'Source / status', 'Action']}>
            {sales.map((sale) => (
                <tr key={sale.id}>
                    <td className="px-4 py-3 font-semibold">{sale.receipt || `Sale #${sale.id}`}</td>
                    <td className="px-4 py-3">{sale.fuel}</td>
                    <td className="px-4 py-3">{sale.branch} · {sale.pump} / {sale.nozzle}</td>
                    <td className="px-4 py-3 whitespace-nowrap">{dateTime(sale.sold_at)}</td>
                    <td className="px-4 py-3">{liters(sale.liters)}</td>
                    <td className="px-4 py-3 font-bold">{money(sale.amount)}</td>
                    <td className="px-4 py-3"><div className="grid justify-items-start gap-1"><Status>{sale.status}</Status>{sale.is_imported && <span className="text-[10px] font-bold text-slate-500">Imported · read-only</span>}</div></td>
                    <td className="px-4 py-3">
                        {sale.can_edit ? (
                            <details className="relative">
                                <summary className="cursor-pointer text-xs font-bold text-rose-700">Edit / void</summary>
                                <div className="absolute right-0 z-10 mt-2 w-72 rounded-xl border border-slate-200 bg-white p-3 shadow-xl">
                                    <Form action={`/sales/${sale.id}`} className="grid gap-2" method="PUT">
                                        <Field label="Receipt number"><Input defaultValue={sale.receipt ?? ''} maxLength="100" name="receipt_number" /></Field>
                                        <Field label="Liters"><Input defaultValue={sale.liters} min="0.001" name="liters" required step="0.001" type="number" /></Field>
                                        <Field label="Payment method"><Select defaultValue={sale.payment_method || ''} name="payment_method"><option value="">Not specified</option><option value="cash">Cash</option><option value="card">Card</option><option value="ewallet">E-wallet</option><option value="other">Other</option></Select></Field>
                                        <Field label="Reason"><Input name="edit_reason" placeholder="Required for audit" required /></Field>
                                        <ActionButton type="submit">Save correction</ActionButton>
                                    </Form>
                                    <Form action={`/sales/${sale.id}/void`} className="mt-3 grid gap-2" method="POST">
                                        <Field label="Void reason"><Input name="void_reason" required /></Field>
                                        <ActionButton type="submit">Void and reverse stock</ActionButton>
                                    </Form>
                                </div>
                            </details>
                        ) : <span className="text-xs text-slate-400">{sale.is_imported ? 'Locked' : '—'}</span>}
                    </td>
                </tr>
            ))}
            {!sales.length && <tr><td className="px-4 py-8 text-center text-slate-500" colSpan="8">No sales recorded in this database yet.</td></tr>}
        </Table>
    );
}

function Dashboard({ data, userName }) {
    const summary = data.summary;
    return (
        <>
            <div className="mb-6">
                <p className="text-[11px] font-extrabold uppercase tracking-[0.18em] text-rose-700">Live station data</p>
                <h1 className="mt-1 text-3xl font-extrabold text-slate-900">Welcome, {userName.split(' ')[0]}</h1>
                <p className="mt-1 text-sm text-slate-500">Figures below are loaded from the station database, not demo values.</p>
            </div>
            <div className="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                {[
                    ['Today’s sales', money(summary.today_sales)],
                    ['Fuel dispensed today', liters(summary.today_liters)],
                    ['Current inventory', liters(summary.stock_liters)],
                    ['Pending reviews', summary.pending_deliveries + summary.pending_adjustments],
                ].map(([label, value]) => <article className="rounded-2xl border border-slate-200 bg-white p-5" key={label}><p className="text-sm text-slate-500">{label}</p><p className="mt-2 text-2xl font-extrabold text-slate-900">{value}</p></article>)}
            </div>
            <Panel title="Current fuel stock" subtitle="By branch, product, and physical tank">
                <Table headers={['Branch', 'Tank', 'Fuel', 'Current stock', 'Capacity', 'Level']}>
                    {data.inventory.map((tank) => {
                        const percent = tank.capacity_liters ? Math.max(0, Math.min(100, tank.stock_liters / tank.capacity_liters * 100)) : 0;
                        return <tr key={tank.id}><td className="px-4 py-3">{tank.branch}</td><td className="px-4 py-3 font-semibold">{tank.code}</td><td className="px-4 py-3">{tank.fuel}</td><td className="px-4 py-3">{liters(tank.stock_liters)}</td><td className="px-4 py-3">{liters(tank.capacity_liters)}</td><td className="px-4 py-3">{percent.toFixed(1)}%</td></tr>;
                    })}
                    {!data.inventory.length && <tr><td className="px-4 py-8 text-center text-slate-500" colSpan="6">No tank data found.</td></tr>}
                </Table>
            </Panel>
            <Panel title="Recent transactions" subtitle="Most recent recorded sales">
                <SalesTable sales={data.sales.slice(0, 8)} />
            </Panel>
        </>
    );
}

function Inventory({ data }) {
    return (
        <>
            <div className="mb-6 flex flex-wrap items-end justify-between gap-3">
                <div><p className="text-[11px] font-extrabold uppercase tracking-widest text-rose-700">Live stock ledger</p><h1 className="text-3xl font-extrabold text-slate-900">Fuel inventory</h1><p className="mt-1 text-sm text-slate-500">Balances are calculated from recorded inventory movements.</p></div>
                <a className="rounded-xl bg-gradient-to-r from-rose-600 to-blue-800 px-4 py-2.5 text-sm font-bold text-white" href="/setup">Manage tanks</a>
            </div>
            <Panel title="Tank levels" subtitle={`${data.inventory.length} tanks`}>
                <Table headers={['Branch', 'Tank', 'Fuel', 'Nozzles', 'Stock', 'Capacity', 'Level', 'Status']}>
                    {data.inventory.map((tank) => {
                        const percent = tank.capacity_liters ? Math.max(0, Math.min(100, tank.stock_liters / tank.capacity_liters * 100)) : 0;
                        return <tr key={tank.id}><td className="px-4 py-3">{tank.branch}</td><td className="px-4 py-3 font-semibold">{tank.code}</td><td className="px-4 py-3">{tank.fuel}</td><td className="px-4 py-3">{tank.nozzle_count}</td><td className="px-4 py-3">{liters(tank.stock_liters)}</td><td className="px-4 py-3">{liters(tank.capacity_liters)}</td><td className="px-4 py-3">{percent.toFixed(1)}%</td><td className="px-4 py-3"><Status>{tank.is_active ? 'Active' : 'Archived'}</Status></td></tr>;
                    })}
                    {!data.inventory.length && <tr><td className="px-4 py-8 text-center text-slate-500" colSpan="8">No tanks configured.</td></tr>}
                </Table>
            </Panel>
        </>
    );
}

function Transactions({ data }) {
    const [query, setQuery] = useState('');
    const filtered = useMemo(() => data.sales.filter((sale) => `${sale.receipt} ${sale.fuel} ${sale.branch} ${sale.pump} ${sale.nozzle}`.toLowerCase().includes(query.toLowerCase())), [data.sales, query]);
    return (
        <>
            <div className="mb-6"><p className="text-[11px] font-extrabold uppercase tracking-widest text-rose-700">Activity ledger</p><h1 className="text-3xl font-extrabold text-slate-900">Transactions</h1><p className="mt-1 text-sm text-slate-500">Imported transactions are marked read-only and cannot be changed or voided.</p></div>
            <Panel title="Sales activity" subtitle={`${filtered.length} records on this page · ${data.pagination.sales.total} total`} action={<Input aria-label="Search sales on this page" onChange={(event) => setQuery(event.target.value)} placeholder="Search this page…" value={query} />}>
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
                <Panel title="Recent sales" subtitle="Database records · imported sales are immutable">
                    <SalesTable sales={data.sales.slice(0, 15)} />
                    <PageNavigation pagination={data.pagination.sales} parameter="sales_page" />
                </Panel>
            </div>
        </>
    );
}

function Deliveries({ data, root }) {
    const availableTanks = data.inventory.filter((tank) => tank.is_active && (!root.dataset.activeBranchId || String(tank.branch_id) === root.dataset.activeBranchId));
    return (
        <>
            <div className="mb-6"><p className="text-[11px] font-extrabold uppercase tracking-widest text-rose-700">Stock-in</p><h1 className="text-3xl font-extrabold text-slate-900">Fuel deliveries</h1><p className="mt-1 text-sm text-slate-500">Pending deliveries can be edited or archived; approved volumes remain in the audit ledger.</p></div>
            <div className="mb-5 grid gap-5 xl:grid-cols-[.8fr_1.2fr]">
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
                    <Table headers={['Branch · Tank', 'Supplier / reference', 'Received', 'Liters', 'Cost', 'Status', 'Review']}>
                        {data.deliveries.map((delivery) => <tr key={delivery.id}>
                            <td className="px-4 py-3">{delivery.branch} · {delivery.tank} ({delivery.fuel})</td><td className="px-4 py-3">{delivery.supplier || '—'} / {delivery.reference || '—'}</td><td className="px-4 py-3 whitespace-nowrap">{dateTime(delivery.received_at)}</td><td className="px-4 py-3">{liters(delivery.liters)}</td><td className="px-4 py-3">{money(delivery.total_cost)}</td><td className="px-4 py-3"><Status>{delivery.status}</Status></td><td className="px-4 py-3">
                                {delivery.status === 'pending' && <div className="flex flex-wrap gap-2">
                                    {delivery.can_edit && <details><summary className="cursor-pointer text-xs font-bold text-rose-700">Edit</summary><Form action={`/deliveries/${delivery.id}`} className="absolute z-10 mt-2 grid w-64 gap-2 rounded-xl border border-slate-200 bg-white p-3 shadow-xl" method="PUT">
                                        <Field label="Tank"><Select defaultValue={delivery.tank_id} name="fuel_tank_id" required>{availableTanks.filter((tank) => tank.branch_id === delivery.branch_id).map((tank) => <option key={tank.id} value={tank.id}>{tank.code} · {tank.fuel}</option>)}</Select></Field>
                                        <Field label="Liters"><Input defaultValue={delivery.liters} min="0.001" name="delivered_liters" required step="0.001" type="number" /></Field>
                                        <Field label="Cost per liter"><Input defaultValue={delivery.cost_per_liter} min="0" name="cost_per_liter" required step="0.01" type="number" /></Field>
                                        <Field label="Supplier"><Input defaultValue={delivery.supplier || ''} name="supplier_name" /></Field>
                                        <Field label="Reference"><Input defaultValue={delivery.reference || ''} name="delivery_reference" /></Field>
                                        <Field label="Received at"><Input defaultValue={localDateTime()} name="received_at" required type="datetime-local" /></Field>
                                        <ActionButton type="submit">Save pending delivery</ActionButton>
                                    </Form></details>}
                                    <Form action={`/deliveries/${delivery.id}/approve`}><ActionButton type="submit">Approve</ActionButton></Form>
                                    <Form action={`/deliveries/${delivery.id}/reject`} className="flex gap-1"><Input className="max-w-28" name="review_notes" placeholder="Reason" required /><ActionButton type="submit">Archive</ActionButton></Form>
                                </div>}
                                {delivery.can_reverse && <Form action={`/deliveries/${delivery.id}/void`} className="grid gap-1"><Input name="void_reason" placeholder="Reversal reason" required /><ActionButton type="submit">Reverse posted delivery</ActionButton></Form>}
                            </td>
                        </tr>)}
                        {!data.deliveries.length && <tr><td className="px-4 py-8 text-center text-slate-500" colSpan="7">No deliveries recorded.</td></tr>}
                    </Table>
                    <PageNavigation pagination={data.pagination.deliveries} parameter="deliveries_page" />
                </Panel>
            </div>
        </>
    );
}

function Adjustments({ data, root }) {
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
                <Panel title="Adjustment history" subtitle={`${data.adjustments.length} latest records`}>
                    <Table headers={['Branch · Tank', 'Change', 'Reason', 'Requested by', 'Status', 'Action']}>
                        {data.adjustments.map((adjustment) => <tr key={adjustment.id}>
                            <td className="px-4 py-3">{adjustment.branch} · {adjustment.tank} ({adjustment.fuel})</td>
                            <td className="px-4 py-3">{liters(adjustment.quantity_change_liters)}</td>
                            <td className="px-4 py-3">{adjustment.reason}</td>
                            <td className="px-4 py-3">{adjustment.requester}</td>
                            <td className="px-4 py-3"><Status>{adjustment.status}</Status></td>
                            <td className="px-4 py-3">
                                {adjustment.status === 'pending' && <div className="flex flex-wrap gap-2">
                                    {adjustment.can_edit && <details><summary className="cursor-pointer text-xs font-bold text-rose-700">Edit</summary><Form action={`/adjustments/${adjustment.id}`} className="absolute z-10 mt-2 grid w-64 gap-2 rounded-xl border border-slate-200 bg-white p-3 shadow-xl" method="PUT">
                                        <Field label="Tank"><Select defaultValue={adjustment.tank_id} name="fuel_tank_id" required>{tanks.filter((tank) => tank.branch_id === adjustment.branch_id).map((tank) => <option key={tank.id} value={tank.id}>{tank.code} · {tank.fuel}</option>)}</Select></Field>
                                        <Field label="Quantity change"><Input defaultValue={adjustment.quantity_change_liters} name="quantity_change_liters" required step="0.001" type="number" /></Field>
                                        <Field label="Reason"><Input defaultValue={adjustment.reason} name="reason" required /></Field>
                                        <ActionButton type="submit">Save pending adjustment</ActionButton>
                                    </Form></details>}
                                    <Form action={`/adjustments/${adjustment.id}/approve`}><ActionButton type="submit">Approve</ActionButton></Form>
                                    <Form action={`/adjustments/${adjustment.id}/reject`} className="flex gap-1"><Input className="max-w-28" name="review_notes" placeholder="Reason" required /><ActionButton type="submit">Archive</ActionButton></Form>
                                </div>}
                                {adjustment.can_reverse && <Form action={`/adjustments/${adjustment.id}/void`} className="grid gap-1"><Input name="void_reason" placeholder="Reversal reason" required /><ActionButton type="submit">Reverse posted adjustment</ActionButton></Form>}
                            </td>
                        </tr>)}
                        {!data.adjustments.length && <tr><td className="px-4 py-8 text-center text-slate-500" colSpan="6">No adjustments recorded.</td></tr>}
                    </Table>
                    <PageNavigation pagination={data.pagination.adjustments} parameter="adjustments_page" />
                </Panel>
            </div>
        </>
    );
}

function Prices({ data, root }) {
    const activeProducts = data.products.filter((product) => product.is_active);
    return (
        <>
            <div className="mb-6"><p className="text-[11px] font-extrabold uppercase tracking-widest text-rose-700">Price management</p><h1 className="text-3xl font-extrabold text-slate-900">Fuel prices</h1><p className="mt-1 text-sm text-slate-500">New prices append to effective-price history; sales keep their original price.</p></div>
            <div className="mb-5">
                <Panel title="Set a price" subtitle="Creates a new effective price record">
                    <Form action="/setup/prices" className="grid gap-4 p-5 md:grid-cols-4">
                        <BranchField data={data} root={root} />
                        <Field label="Fuel"><Select name="fuel_product_id" required>{activeProducts.map((product) => <option key={product.id} value={product.id}>{product.name}</option>)}</Select></Field>
                        <Field label="Price per liter"><Input min="0.01" name="price_per_liter" required step="0.01" type="number" /></Field>
                        <div className="flex items-end"><ActionButton className="min-h-10" type="submit">Add effective price</ActionButton></div>
                    </Form>
                </Panel>
            </div>
            <Panel title="Current price by branch" subtitle="Price corrections create a new audited effective-price record; existing sales remain unchanged">
                <Table headers={['Branch', 'Fuel', 'Price per liter', 'Effective from', 'Set by', 'Action']}>
                    {data.prices.map((price) => <tr key={price.id}>
                        <td className="px-4 py-3">{price.branch}</td>
                        <td className="px-4 py-3 font-semibold">{price.fuel}</td>
                        <td className="px-4 py-3">{money(price.price_per_liter)}</td>
                        <td className="px-4 py-3">{dateTime(price.effective_from)}</td>
                        <td className="px-4 py-3">{price.setter}</td>
                        <td className="px-4 py-3">
                            <details className="relative">
                                <summary className="cursor-pointer text-xs font-bold text-rose-700">Correct price</summary>
                                <Form action={`/setup/prices/${price.id}`} className="absolute right-0 z-10 mt-2 grid w-64 gap-2 rounded-xl border border-slate-200 bg-white p-3 shadow-xl" method="PUT">
                                    <Field label="New price per liter"><Input defaultValue={price.price_per_liter} min="0.01" name="price_per_liter" required step="0.01" type="number" /></Field>
                                    <Field label="Reason"><Input name="reason" placeholder="Optional audit note" /></Field>
                                    <ActionButton type="submit">Save correction</ActionButton>
                                </Form>
                            </details>
                        </td>
                    </tr>)}
                    {!data.prices.length && <tr><td className="px-4 py-8 text-center text-slate-500" colSpan="6">No fuel prices configured.</td></tr>}
                </Table>
            </Panel>
        </>
    );
}

function Reports({ data }) {
    const summary = data.summary;
    const rows = [
        ['Completed sales today', summary.today_transaction_count],
        ['Revenue today', money(summary.today_sales)],
        ['Liters dispensed today', liters(summary.today_liters)],
        ['Current stock on hand', liters(summary.stock_liters)],
        ['Pending deliveries', summary.pending_deliveries],
        ['Pending stock adjustments', summary.pending_adjustments],
    ];
    return (
        <>
            <div className="mb-6"><p className="text-[11px] font-extrabold uppercase tracking-widest text-rose-700">Operations report</p><h1 className="text-3xl font-extrabold text-slate-900">Sales and stock reports</h1><p className="mt-1 text-sm text-slate-500">Current totals are calculated from persisted sales and inventory ledgers.</p></div>
            <Panel title="Live summary" subtitle="Today’s date · current authorized branch scope" action={<a className="text-sm font-bold text-rose-700" href="/api/reports/sales.csv">Download sales CSV</a>}>
                <Table headers={['Measure', 'Current value']}>{rows.map(([label, value]) => <tr key={label}><td className="px-4 py-3 font-semibold">{label}</td><td className="px-4 py-3">{value}</td></tr>)}</Table>
            </Panel>
            <div className="mt-5"><Panel title="Inventory balances"><Table headers={['Branch', 'Tank', 'Fuel', 'Stock', 'Capacity']}>{data.inventory.map((tank) => <tr key={tank.id}><td className="px-4 py-3">{tank.branch}</td><td className="px-4 py-3">{tank.code}</td><td className="px-4 py-3">{tank.fuel}</td><td className="px-4 py-3">{liters(tank.stock_liters)}</td><td className="px-4 py-3">{liters(tank.capacity_liters)}</td></tr>)}</Table></Panel></div>
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
        case 'inventory': return <Inventory data={data} />;
        case 'sales': return <Sales data={data} root={root} />;
        case 'transactions': return <Transactions data={data} />;
        case 'deliveries': return <Deliveries data={data} root={root} />;
        case 'adjustments': return <Adjustments data={data} root={root} />;
        case 'prices': return <Prices data={data} root={root} />;
        case 'reports': return <Reports data={data} />;
        case 'users': return <Users data={data} root={root} />;
        default: return <Dashboard data={data} userName={userName} />;
    }
}

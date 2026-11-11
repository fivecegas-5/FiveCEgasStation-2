import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { requestJson } from './api.js';

const money = (value) => `₱${Number(value ?? 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';
const localDateTime = () => {
    const now = new Date();
    return new Date(now.getTime() - now.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
};

async function submitJson(url, method, data) {
    return requestJson(url, {
        method,
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data),
    });
}

function Field({ label, children }) {
    return <label className="grid gap-1.5 text-sm font-semibold text-slate-700">{label}{children}</label>;
}

function Input(props) {
    return <input className="min-h-10 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm text-slate-800 outline-none focus:border-rose-400 focus:ring-4 focus:ring-rose-100" {...props} />;
}

function Select({ children, ...props }) {
    return <select className="min-h-10 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800" {...props}>{children}</select>;
}

function Button({ children, className = '', ...props }) {
    return <button className={`primary-gradient min-h-10 rounded-xl px-4 py-2 text-sm font-bold text-white transition disabled:opacity-60 ${className}`} {...props}>{children}</button>;
}

function Panel({ title, children, subtitle }) {
    return <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white"><header className="border-b border-slate-100 px-5 py-4"><h2 className="font-bold text-slate-900">{title}</h2>{subtitle && <p className="mt-1 text-xs text-slate-500">{subtitle}</p>}</header>{children}</section>;
}

export function FuelCredit({ root }) {
    const [customers, setCustomers] = useState([]);
    const [agreements, setAgreements] = useState([]);
    const [pumps, setPumps] = useState([]);
    const [nozzles, setNozzles] = useState([]);
    const [selectedPumpId, setSelectedPumpId] = useState('');
    const [selectedNozzleId, setSelectedNozzleId] = useState('');
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');
    const [busy, setBusy] = useState(false);

    const load = useCallback(async () => {
        const [customerResponse, agreementResponse, workspace] = await Promise.all([
            requestJson(root.dataset.creditCustomersUrl),
            requestJson(root.dataset.creditAgreementsUrl),
            requestJson(root.dataset.workspaceUrl),
        ]);
        setCustomers(customerResponse.data?.data ?? []);
        setAgreements(agreementResponse.data?.data ?? []);
        setPumps(workspace.pumps.filter((pump) => pump.is_active
            && (!root.dataset.activeBranchId || String(pump.branch_id) === root.dataset.activeBranchId)));
        setNozzles(workspace.pumps.flatMap((pump) => pump.nozzles
            .filter((nozzle) => pump.is_active && nozzle.is_active)
            .map((nozzle) => ({ ...nozzle, branch_id: pump.branch_id, branch: pump.branch, pump_id: pump.id, pump: pump.label || pump.code }))));
    }, [root]);

    useEffect(() => {
        let cancelled = false;
        Promise.all([
            requestJson(root.dataset.creditCustomersUrl),
            requestJson(root.dataset.creditAgreementsUrl),
            requestJson(root.dataset.workspaceUrl),
        ]).then(([customerResponse, agreementResponse, workspace]) => {
            if (cancelled) return;
            setCustomers(customerResponse.data?.data ?? []);
            setAgreements(agreementResponse.data?.data ?? []);
            setPumps(workspace.pumps.filter((pump) => pump.is_active
                && (!root.dataset.activeBranchId || String(pump.branch_id) === root.dataset.activeBranchId)));
            setNozzles(workspace.pumps.flatMap((pump) => pump.nozzles
                .filter((nozzle) => pump.is_active && nozzle.is_active)
                .map((nozzle) => ({ ...nozzle, branch_id: pump.branch_id, branch: pump.branch, pump_id: pump.id, pump: pump.label || pump.code }))));
        }).catch((loadError) => {
            if (!cancelled) setError(loadError.message);
        });
        return () => { cancelled = true; };
    }, [root]);

    const availableNozzles = useMemo(() => nozzles.filter((nozzle) => (
        !root.dataset.activeBranchId || String(nozzle.branch_id) === root.dataset.activeBranchId
    )), [nozzles, root]);
    const availablePumps = useMemo(() => pumps.filter((pump) => (
        !root.dataset.activeBranchId || String(pump.branch_id) === root.dataset.activeBranchId
    )), [pumps, root]);
    const pumpNozzles = useMemo(() => availableNozzles.filter((nozzle) => (
        String(nozzle.pump_id) === selectedPumpId
    )), [availableNozzles, selectedPumpId]);

    async function runAction(action, successMessage) {
        setBusy(true);
        setError('');
        setNotice('');
        try {
            await action();
            await load();
            setNotice(successMessage);
            return true;
        } catch (actionError) {
            setError(actionError.message);
            return false;
        } finally {
            setBusy(false);
        }
    }

    async function saveCustomer(event, customerId = null) {
        event.preventDefault();
        const formData = new FormData(event.currentTarget);
        const payload = {
            name: formData.get('name'),
            email: formData.get('email') || null,
            phone: formData.get('phone') || null,
        };
        await runAction(
            () => submitJson(
                customerId ? `${root.dataset.creditCustomersUrl}/${customerId}` : root.dataset.creditCustomersUrl,
                customerId ? 'PUT' : 'POST',
                payload,
            ),
            customerId ? 'Customer details updated.' : 'Customer added.',
        );
    }

    async function saveAgreement(event) {
        event.preventDefault();
        const body = new FormData(event.currentTarget);
        if (await runAction(
            () => requestJson(root.dataset.creditAgreementsUrl, { method: 'POST', body }),
            'Agreement and installment schedule recorded.',
        )) {
            event.currentTarget.reset();
            setSelectedPumpId('');
            setSelectedNozzleId('');
        }
    }

    async function recordPayment(event, agreementId) {
        event.preventDefault();
        const body = new FormData(event.currentTarget);
        const payload = Object.fromEntries(body.entries());
        if (await runAction(
            () => submitJson(`${root.dataset.creditAgreementsUrl}/${agreementId}/payments`, 'POST', payload),
            'Payment recorded and allocated to the oldest unpaid installments.',
        )) event.currentTarget.reset();
    }

    async function updateCustomerStatus(customer) {
        await runAction(
            () => submitJson(`${root.dataset.creditCustomersUrl}/${customer.id}/status`, 'PATCH', { is_active: !customer.is_active }),
            customer.is_active ? 'Customer archived; agreement history was retained.' : 'Customer reactivated.',
        );
    }

    async function cancelAgreement(event, agreement) {
        event.preventDefault();
        const reason = new FormData(event.currentTarget).get('reason');
        await runAction(
            () => submitJson(`${root.dataset.creditAgreementsUrl}/${agreement.id}/void`, 'POST', { reason }),
            'Agreement cancelled and the inventory movement reversed.',
        );
    }

    async function reversePayment(event, payment) {
        event.preventDefault();
        const reason = new FormData(event.currentTarget).get('reason');
        await runAction(
            () => submitJson(`${root.dataset.creditPaymentsUrl}/${payment.id}/void`, 'POST', { reason }),
            'Payment reversed and the agreement balance recalculated.',
        );
    }

    async function importCustomers(event) {
        event.preventDefault();
        const form = event.currentTarget;
        const body = new FormData(form);
        if (await runAction(
            () => requestJson(root.dataset.creditCustomersImportUrl, { method: 'POST', body }),
            'Customer records imported.',
        )) form.reset();
    }

    return (
        <>
            <div className="mb-6"><p className="text-[11px] font-extrabold uppercase tracking-widest text-rose-700">Customer accounts</p><h1 className="text-3xl font-extrabold text-slate-900">Fuel credit</h1><p className="mt-1 text-sm text-slate-500">Customers can be edited or archived. Issued agreements and payments retain their financial audit history.</p></div>
            {error && <div className="mb-4 rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800" role="alert">{error}</div>}
            {notice && <div className="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800" role="status">{notice}</div>}
            <div className="mb-5 grid gap-5 xl:grid-cols-2">
                <Panel title="Add customer" subtitle="Customer records belong to the selected branch">
                    <form className="grid gap-3 p-5" onSubmit={(event) => saveCustomer(event)}>
                        <Field label="Customer name"><Input name="name" required /></Field>
                        <Field label="Email (optional)"><Input autoComplete="email" name="email" type="email" /></Field>
                        <Field label="Phone"><Input name="phone" /></Field>
                        <Button disabled={busy} type="submit">Add customer</Button>
                    </form>
                </Panel>
                <Panel title="Issue a credit agreement" subtitle="Creates a sale and deducts stock from the selected pump nozzle">
                    <form className="grid gap-3 p-5" onSubmit={saveAgreement}>
                        <Field label="Customer"><Select name="fuel_credit_customer_id" required><option value="">Select customer</option>{customers.filter((customer) => customer.is_active).map((customer) => <option key={customer.id} value={customer.id}>{customer.name}</option>)}</Select></Field>
                        <Field label="Pump">
                            <Select onChange={(event) => {
                                setSelectedPumpId(event.target.value);
                                setSelectedNozzleId('');
                            }} value={selectedPumpId}>
                                <option value="">Select pump</option>
                                {availablePumps.map((pump) => <option key={pump.id} value={pump.id}>{pump.label || pump.code} · {pump.code} · {pump.branch}</option>)}
                            </Select>
                        </Field>
                        <Field label="Pump nozzle">
                            <Select disabled={!selectedPumpId || !pumpNozzles.length} name="fuel_nozzle_id" onChange={(event) => setSelectedNozzleId(event.target.value)} required value={selectedNozzleId}>
                                <option value="">{!selectedPumpId ? 'Select a pump first' : pumpNozzles.length ? 'Select pump nozzle' : 'No active nozzles on this pump'}</option>
                                {pumpNozzles.map((nozzle) => <option key={nozzle.id} value={nozzle.id}>{nozzle.code} · {nozzle.fuel} · {nozzle.tank}</option>)}
                            </Select>
                        </Field>
                        {!availablePumps.length && <div className="rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800" role="status">
                            No active pumps are configured for {root.dataset.branchName || 'this branch'}. <a className="font-bold underline" href="/setup">Open Station Setup to add a pump and nozzle.</a>
                        </div>}
                        {availablePumps.length > 0 && !availableNozzles.length && <div className="rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800" role="status">
                            No active pump nozzles are configured for {root.dataset.branchName || 'this branch'}. <a className="font-bold underline" href="/setup">Open Station Setup to connect a nozzle to a tank.</a>
                        </div>}
                        <div className="grid gap-3 sm:grid-cols-2"><Field label="Liters"><Input min="0.001" name="liters" required step="0.001" type="number" /></Field><Field label="Monthly payment"><Input min="0.01" name="monthly_payment_amount" required step="0.01" type="number" /></Field></div>
                        <div className="grid gap-3 sm:grid-cols-2"><Field label="First due date"><Input min={new Date().toISOString().slice(0, 10)} name="first_due_date" required type="date" /></Field><Field label="Agreement reference"><Input name="agreement_reference" /></Field></div>
                        <Field label="Agreement document (optional)"><Input accept=".pdf,.jpg,.jpeg,.png" name="agreement" type="file" /></Field>
                        <Button disabled={busy} type="submit">Create agreement</Button>
                    </form>
                </Panel>
            </div>
            <Panel title="Customers" subtitle={`${customers.length} records`}>
                <div className="flex flex-col gap-3 border-b border-slate-100 p-5 sm:flex-row sm:items-end sm:justify-between">
                    <a className="inline-flex min-h-10 items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-700 transition hover:bg-slate-50" href={root.dataset.creditCustomersExportUrl}>Export customer CSV</a>
                    <form className="grid gap-2 sm:grid-cols-[minmax(220px,1fr)_auto]" onSubmit={importCustomers}>
                        <Field label="Import customers (CSV)">
                            <Input accept=".csv,text/csv" name="file" required type="file" />
                        </Field>
                        <Button disabled={busy} type="submit">Import customers</Button>
                    </form>
                </div>
                <div className="grid gap-3 p-5">
                    {customers.map((customer) => <details className="rounded-xl border border-slate-200 p-4" key={customer.id}>
                        <summary className="flex cursor-pointer flex-wrap items-center justify-between gap-3"><span><strong className="text-slate-900">{customer.name}</strong><small className="ml-2 text-slate-500">{customer.email || 'No email'} · {customer.phone || 'No phone'} · {customer.agreements_count} agreements</small></span><span className="text-xs font-bold">{customer.is_active ? 'Active' : 'Archived'}</span></summary>
                        <div className="mt-4 flex flex-wrap items-end gap-3">
                            <form className="grid flex-1 gap-3 sm:grid-cols-2" onSubmit={(event) => saveCustomer(event, customer.id)}>
                                <Field label="Name"><Input defaultValue={customer.name} name="name" required /></Field>
                                <Field label="Email"><Input autoComplete="email" defaultValue={customer.email || ''} name="email" type="email" /></Field>
                                <Field label="Phone"><Input defaultValue={customer.phone || ''} name="phone" /></Field>
                                <Button className="min-h-9 justify-self-start px-4 py-1.5" disabled={busy} type="submit">Save customer</Button>
                            </form>
                            <Button disabled={busy} onClick={() => updateCustomerStatus(customer)} type="button">{customer.is_active ? 'Archive customer' : 'Reactivate customer'}</Button>
                        </div>
                    </details>)}
                    {!customers.length && <p className="py-5 text-center text-sm text-slate-500">No customer records found.</p>}
                </div>
            </Panel>
            <div className="mt-5">
                <Panel title="Credit agreements" subtitle={`${agreements.length} records`}>
                    <div className="grid gap-4 p-5">
                        {agreements.map((agreement) => <article className="rounded-xl border border-slate-200 p-4" key={agreement.id}>
                            <div className="flex flex-wrap items-start justify-between gap-3">
                                <div><h3 className="font-bold text-slate-900">{agreement.customer.name} · {agreement.fuel}</h3><p className="mt-1 text-xs text-slate-500">{agreement.agreement_reference || `Agreement #${agreement.id}`} · {agreement.liters} L · {agreement.status}</p></div>
                                <div className="text-right"><p className="font-extrabold text-slate-900">{money(agreement.balance)} due</p><p className="text-xs text-slate-500">Borrowed {money(agreement.value_borrowed)}</p></div>
                            </div>
                            {agreement.status === 'active' && <div className="mt-4 grid gap-4 lg:grid-cols-[1fr_auto]">
                                <form className="grid gap-3 sm:grid-cols-4" onSubmit={(event) => recordPayment(event, agreement.id)}>
                                    <Field label="Payment amount"><Input min="0.01" max={agreement.balance} name="amount" required step="0.01" type="number" /></Field>
                                    <Field label="Paid at"><Input defaultValue={localDateTime()} max={localDateTime()} name="paid_at" required type="datetime-local" /></Field>
                                    <Field label="Method"><Select name="payment_method"><option value="">Unspecified</option><option value="cash">Cash</option><option value="card">Card</option><option value="ewallet">E-wallet</option><option value="bank_transfer">Bank transfer</option><option value="other">Other</option></Select></Field>
                                    <Field label="Reference"><Input name="reference" /></Field>
                                    <Field label="Notes"><Input className="sm:col-span-3" name="notes" /></Field>
                                    <div className="flex items-end"><Button disabled={busy} type="submit">Record payment</Button></div>
                                </form>
                                {agreement.can_cancel && <form className="grid content-end gap-2" onSubmit={(event) => cancelAgreement(event, agreement)}>
                                    <Field label="Cancellation reason"><Input name="reason" required /></Field><Button disabled={busy} type="submit">Cancel and reverse</Button>
                                </form>}
                            </div>}
                            {!!agreement.payments?.length && <div className="mt-4 border-t border-slate-100 pt-4">
                                <h4 className="mb-2 text-sm font-bold text-slate-800">Payment history</h4>
                                <div className="grid gap-3">
                                    {agreement.payments.map((payment) => <div className="rounded-xl bg-slate-50 p-3" key={payment.id}>
                                        <div className="flex flex-wrap items-center justify-between gap-2 text-sm">
                                            <span className="font-bold text-slate-900">{money(payment.amount)} · {payment.payment_method || 'Unspecified'}</span>
                                            <span className={payment.status === 'posted' ? 'font-bold text-emerald-700' : 'font-bold text-slate-500'}>{payment.status}</span>
                                        </div>
                                        <p className="mt-1 text-xs text-slate-500">{payment.paid_at ? new Date(payment.paid_at).toLocaleString() : 'Date unavailable'}{payment.reference ? ` · Ref: ${payment.reference}` : ''}{payment.void_reason ? ` · Reversal: ${payment.void_reason}` : ''}</p>
                                        {payment.can_void && <form className="mt-3 flex flex-wrap items-end gap-2" onSubmit={(event) => reversePayment(event, payment)}>
                                            <Field label="Reversal reason"><Input name="reason" required /></Field>
                                            <Button disabled={busy} type="submit">Reverse payment</Button>
                                        </form>}
                                    </div>)}
                                </div>
                            </div>}
                            <div className="mt-3 text-xs text-slate-500">Payment records remain in the audit history. Posted payments can be reversed with a reason; the original entry is never deleted.</div>
                        </article>)}
                        {!agreements.length && <p className="py-5 text-center text-sm text-slate-500">No credit agreements found.</p>}
                    </div>
                </Panel>
            </div>
        </>
    );
}

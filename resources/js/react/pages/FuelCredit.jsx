import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { requestJson } from '../api.js';

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

function Button({ children, ...props }) {
    return <button className="min-h-10 rounded-xl bg-gradient-to-r from-rose-600 to-blue-800 px-4 py-2 text-sm font-bold text-white disabled:opacity-60" {...props}>{children}</button>;
}

function Panel({ title, children, subtitle }) {
    return <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white"><header className="border-b border-slate-100 px-5 py-4"><h2 className="font-bold text-slate-900">{title}</h2>{subtitle && <p className="mt-1 text-xs text-slate-500">{subtitle}</p>}</header>{children}</section>;
}

export function FuelCredit({ root }) {
    const [customers, setCustomers] = useState([]);
    const [agreements, setAgreements] = useState([]);
    const [nozzles, setNozzles] = useState([]);
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
        setNozzles(workspace.pumps.flatMap((pump) => pump.nozzles
            .filter((nozzle) => pump.is_active && nozzle.is_active)
            .map((nozzle) => ({ ...nozzle, branch: pump.branch, pump: pump.label || pump.code }))));
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
            setNozzles(workspace.pumps.flatMap((pump) => pump.nozzles
                .filter((nozzle) => pump.is_active && nozzle.is_active)
                .map((nozzle) => ({ ...nozzle, branch: pump.branch, pump: pump.label || pump.code }))));
        }).catch((loadError) => {
            if (!cancelled) setError(loadError.message);
        });
        return () => { cancelled = true; };
    }, [root]);

    const availableNozzles = useMemo(() => nozzles.filter((nozzle) => (
        !root.dataset.activeBranchId || String(nozzle.branch_id) === root.dataset.activeBranchId
    )), [nozzles, root]);

    async function runAction(action, successMessage) {
        setBusy(true);
        setError('');
        setNotice('');
        try {
            await action();
            await load();
            setNotice(successMessage);
        } catch (actionError) {
            setError(actionError.message);
        } finally {
            setBusy(false);
        }
    }

    async function saveCustomer(event, customerId = null) {
        event.preventDefault();
        const formData = new FormData(event.currentTarget);
        const payload = { name: formData.get('name'), phone: formData.get('phone') || null };
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
        await runAction(() => requestJson(root.dataset.creditAgreementsUrl, { method: 'POST', body }), 'Agreement and installment schedule recorded.');
        event.currentTarget.reset();
    }

    async function recordPayment(event, agreementId) {
        event.preventDefault();
        const body = new FormData(event.currentTarget);
        const payload = Object.fromEntries(body.entries());
        await runAction(
            () => submitJson(`${root.dataset.creditAgreementsUrl}/${agreementId}/payments`, 'POST', payload),
            'Payment recorded and allocated to the oldest unpaid installments.',
        );
        event.currentTarget.reset();
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

    return (
        <>
            <div className="mb-6"><p className="text-[11px] font-extrabold uppercase tracking-widest text-rose-700">Customer accounts</p><h1 className="text-3xl font-extrabold text-slate-900">Fuel credit</h1><p className="mt-1 text-sm text-slate-500">Customers can be edited or archived. Issued agreements and payments retain their financial audit history.</p></div>
            {error && <div className="mb-4 rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800" role="alert">{error}</div>}
            {notice && <div className="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800" role="status">{notice}</div>}
            <div className="mb-5 grid gap-5 xl:grid-cols-2">
                <Panel title="Add customer" subtitle="Customer records belong to the selected branch">
                    <form className="grid gap-3 p-5" onSubmit={(event) => saveCustomer(event)}>
                        <Field label="Customer name"><Input name="name" required /></Field>
                        <Field label="Phone"><Input name="phone" /></Field>
                        <Button disabled={busy} type="submit">Add customer</Button>
                    </form>
                </Panel>
                <Panel title="Issue a credit agreement" subtitle="Creates a sale and deducts stock from the selected nozzle">
                    <form className="grid gap-3 p-5" onSubmit={saveAgreement}>
                        <Field label="Customer"><Select name="fuel_credit_customer_id" required><option value="">Select customer</option>{customers.filter((customer) => customer.is_active).map((customer) => <option key={customer.id} value={customer.id}>{customer.name}</option>)}</Select></Field>
                        <Field label="Pump nozzle"><Select name="fuel_nozzle_id" required><option value="">Select pump nozzle</option>{availableNozzles.map((nozzle) => <option key={nozzle.id} value={nozzle.id}>{nozzle.branch} · {nozzle.pump} · {nozzle.code} · {nozzle.fuel}</option>)}</Select></Field>
                        <div className="grid gap-3 sm:grid-cols-2"><Field label="Liters"><Input min="0.001" name="liters" required step="0.001" type="number" /></Field><Field label="Monthly payment"><Input min="0.01" name="monthly_payment_amount" required step="0.01" type="number" /></Field></div>
                        <div className="grid gap-3 sm:grid-cols-2"><Field label="First due date"><Input min={new Date().toISOString().slice(0, 10)} name="first_due_date" required type="date" /></Field><Field label="Agreement reference"><Input name="agreement_reference" /></Field></div>
                        <Field label="Agreement document (optional)"><Input accept=".pdf,.jpg,.jpeg,.png" name="agreement" type="file" /></Field>
                        <Button disabled={busy} type="submit">Create agreement</Button>
                    </form>
                </Panel>
            </div>
            <Panel title="Customers" subtitle={`${customers.length} records`}>
                <div className="grid gap-3 p-5">
                    {customers.map((customer) => <details className="rounded-xl border border-slate-200 p-4" key={customer.id}>
                        <summary className="flex cursor-pointer flex-wrap items-center justify-between gap-3"><span><strong className="text-slate-900">{customer.name}</strong><small className="ml-2 text-slate-500">{customer.phone || 'No phone'} · {customer.agreements_count} agreements</small></span><span className="text-xs font-bold">{customer.is_active ? 'Active' : 'Archived'}</span></summary>
                        <div className="mt-4 flex flex-wrap items-end gap-3">
                            <form className="grid flex-1 gap-3 sm:grid-cols-2" onSubmit={(event) => saveCustomer(event, customer.id)}>
                                <Field label="Name"><Input defaultValue={customer.name} name="name" required /></Field>
                                <Field label="Phone"><Input defaultValue={customer.phone || ''} name="phone" /></Field>
                                <Button disabled={busy} type="submit">Save customer</Button>
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

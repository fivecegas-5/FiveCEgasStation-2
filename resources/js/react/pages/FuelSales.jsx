import React, { useMemo, useState } from 'react';
import { requestJson } from '../api.js';

const currency = new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: 'PHP',
});

function FuelIcon({ type }) {
    if (type === 'premium') {
        return (
            <svg viewBox="0 0 32 32" aria-hidden="true">
                <path d="m16 3 3.8 8.1 8.9 1.3-6.4 6.2 1.5 8.8L16 23.2l-7.8 4.2 1.5-8.8-6.4-6.2 8.9-1.3L16 3Z" />
            </svg>
        );
    }

    if (type === 'diesel') {
        return (
            <svg viewBox="0 0 32 32" aria-hidden="true">
                <path d="M7 27V7a3 3 0 0 1 3-3h10a3 3 0 0 1 3 3v20M7 10h16M10 15h8M23 11h3l3 4v9a3 3 0 0 1-6 0v-3M4 27h22" />
                <path d="M13 18c-2 2-3 3.5-3 5a3 3 0 0 0 6 0c0-1.5-1-3-3-5Z" />
            </svg>
        );
    }

    return (
        <svg viewBox="0 0 32 32" aria-hidden="true">
            <path d="M16 3S7 13 7 20a9 9 0 0 0 18 0c0-7-9-17-9-17Z" />
            <path d="M12 21a4 4 0 0 0 4 4" />
        </svg>
    );
}

function fuelIconType(label) {
    const normalized = label.toLowerCase();
    if (normalized.includes('premium')) return 'premium';
    if (normalized.includes('diesel')) return 'diesel';
    return 'unleaded';
}

export function FuelSales({ root }) {
    const fuels = useMemo(() => JSON.parse(root.dataset.fuels || '[]'), [root]);
    const [selectedId, setSelectedId] = useState('');
    const [nozzleId, setNozzleId] = useState('');
    const [liters, setLiters] = useState('');
    const [receipt, setReceipt] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');

    const selectedFuel = fuels.find((fuel) => String(fuel.id) === selectedId) ?? null;
    const selectedNozzle = selectedFuel?.nozzles.find((nozzle) => String(nozzle.id) === nozzleId) ?? null;
    const total = selectedFuel?.price_per_liter && liters
        ? Number(liters) * selectedFuel.price_per_liter
        : 0;

    function selectFuel(fuel) {
        setSelectedId(String(fuel.id));
        setNozzleId(fuel.nozzles.length === 1 ? String(fuel.nozzles[0].id) : '');
        setError('');
        setNotice('');
    }

    async function submitSale(event) {
        event.preventDefault();
        if (!selectedFuel || !selectedNozzle) {
            setError('Choose a fuel and pump/nozzle before recording the sale.');
            return;
        }

        setBusy(true);
        setError('');
        setNotice('');
        try {
            const result = await requestJson(root.dataset.storeUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    fuel_nozzle_id: selectedNozzle.id,
                    liters,
                    receipt_number: receipt.trim() || null,
                    sold_at: new Date().toISOString(),
                }),
            });
            setNotice(result.message);
            setLiters('');
            setReceipt('');
        } catch (requestError) {
            setError(requestError.message);
        } finally {
            setBusy(false);
        }
    }

    return (
        <div className="fuel-sales-entry">
            <div className="fuel-sales-heading">
                <div>
                    <span className="eyebrow">CUSTOMER TRANSACTIONS</span>
                    <h2>Record a fuel sale</h2>
                    <p>Select the fuel, nozzle, and liters dispensed. The current branch price is applied automatically.</p>
                </div>
                <span className="fuel-sales-branch"><i className="status-dot" />{root.dataset.branchName}</span>
            </div>

            {error && <div className="alert alert-error" role="alert">{error}</div>}
            {notice && <div className="alert alert-success" role="status">{notice}</div>}

            {fuels.length === 0 ? (
                <div className="panel fuel-sales-empty">
                    <strong>No active fuel nozzles are configured for this branch.</strong>
                    <p>Ask the owner to finish station setup before recording sales.</p>
                </div>
            ) : (
                <form className="fuel-sales-form" onSubmit={submitSale}>
                    <fieldset className="fuel-choice-fieldset">
                        <legend>Choose fuel type</legend>
                        <div className="fuel-choice-grid">
                            {fuels.map((fuel) => {
                                const isSelected = String(fuel.id) === selectedId;
                                const isUnavailable = !fuel.id
                                    || fuel.price_per_liter === null
                                    || fuel.nozzles.length === 0;
                                const unavailableMessage = fuel.price_per_liter === null
                                    ? 'Price not set'
                                    : fuel.nozzles.length === 0
                                        ? 'No nozzle configured'
                                        : null;
                                return (
                                    <button
                                        className={`fuel-choice${isSelected ? ' is-selected' : ''}`}
                                        type="button"
                                        key={fuel.id ?? fuel.label}
                                        aria-pressed={isSelected}
                                        disabled={isUnavailable}
                                        onClick={() => selectFuel(fuel)}
                                    >
                                        <span className={`fuel-choice-icon fuel-icon-${fuelIconType(fuel.label)}`}>
                                            <FuelIcon type={fuelIconType(fuel.label)} />
                                        </span>
                                        <span className="fuel-choice-copy">
                                            <strong>{fuel.label}</strong>
                                            <small>{fuel.name}</small>
                                        </span>
                                        <span className="fuel-choice-price">
                                            {fuel.price_per_liter === null
                                                ? unavailableMessage
                                                : `${currency.format(fuel.price_per_liter)}/L`}
                                            {fuel.price_per_liter !== null && unavailableMessage && (
                                                <small>{unavailableMessage}</small>
                                            )}
                                        </span>
                                    </button>
                                );
                            })}
                        </div>
                    </fieldset>

                    {selectedFuel && (
                        <div className="fuel-sale-fields">
                            <label>
                                Pump and nozzle
                                <select
                                    value={nozzleId}
                                    onChange={(event) => setNozzleId(event.target.value)}
                                    required
                                >
                                    <option value="">Choose a pump/nozzle</option>
                                    {selectedFuel.nozzles.map((nozzle) => (
                                        <option key={nozzle.id} value={nozzle.id}>
                                            {nozzle.pump} / {nozzle.code}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label>
                                Liters dispensed
                                <input
                                    type="number"
                                    min="0.001"
                                    max="100000"
                                    step="0.001"
                                    value={liters}
                                    onChange={(event) => setLiters(event.target.value)}
                                    placeholder="0.000"
                                    required
                                />
                            </label>
                            <label>
                                Receipt number <span className="optional">OPTIONAL</span>
                                <input
                                    value={receipt}
                                    onChange={(event) => setReceipt(event.target.value)}
                                    maxLength="100"
                                    placeholder="Enter receipt number"
                                />
                            </label>
                            <div className="fuel-sale-total">
                                <span><small>PRICE PER LITER</small><strong>{currency.format(selectedFuel.price_per_liter)}</strong></span>
                                <span><small>SALE TOTAL</small><strong>{currency.format(total)}</strong></span>
                            </div>
                            <button className="button button-primary fuel-sale-submit" type="submit" disabled={busy || !selectedNozzle}>
                                {busy ? 'Recording sale…' : 'Record sale'}
                            </button>
                        </div>
                    )}
                </form>
            )}
        </div>
    );
}

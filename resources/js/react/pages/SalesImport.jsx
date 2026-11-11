import React, { useCallback, useEffect, useState } from 'react';
import { requestJson } from './api.js';

const dateTime = (value) => value
    ? new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
    : '—';

export function SalesImport({ root }) {
    const [batches, setBatches] = useState([]);
    const [selected, setSelected] = useState(null);
    const [file, setFile] = useState(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');

    const loadBatches = useCallback(async () => {
        const response = await requestJson(root.dataset.batchesUrl);
        setBatches(response.data?.data ?? []);
    }, [root]);

    const loadBatch = useCallback(async (url) => {
        const response = await requestJson(url);
        setSelected({
            ...response.data,
            rows: response.rows?.data ?? [],
            rowsUrl: url,
            canImport: response.can_import,
        });
    }, []);

    useEffect(() => {
        let cancelled = false;
        requestJson(root.dataset.batchesUrl)
            .then((response) => {
                if (!cancelled) setBatches(response.data?.data ?? []);
            })
            .catch((requestError) => {
                if (!cancelled) setError(requestError.message);
            });

        return () => {
            cancelled = true;
        };
    }, [root]);

    async function preview(event) {
        event.preventDefault();
        if (!file) {
            setError('Choose a CSV file first.');
            return;
        }

        setBusy(true);
        setError('');
        setNotice('');
        const body = new FormData();
        body.append('file', file);

        try {
            const response = await requestJson(root.dataset.previewUrl, { method: 'POST', body });
            await loadBatch(response.rows_url);
            await loadBatches();
            setNotice(response.message ?? 'Preview ready. Review every row before confirming the import.');
        } catch (requestError) {
            setError(requestError.message);
        } finally {
            setBusy(false);
        }
    }

    async function confirmImport() {
        if (!selected?.canImport) return;

        setBusy(true);
        setError('');
        setNotice('');
        try {
            const batchUrl = root.dataset.batchUrlTemplate.replace('__BATCH_ID__', selected.id);
            const response = await requestJson(`${batchUrl}/confirm`, {
                method: 'POST',
            });
            await loadBatch(selected.rowsUrl);
            await loadBatches();
            setNotice(response.message ?? 'All sales in this file were imported successfully.');
        } catch (requestError) {
            setError(requestError.message);
            if (selected?.rowsUrl) {
                try {
                    await loadBatch(selected.rowsUrl);
                    await loadBatches();
                } catch (refreshError) {
                    setError(`${requestError.message} Could not refresh the preview: ${refreshError.message}`);
                }
            }
        } finally {
            setBusy(false);
        }
    }

    async function selectBatch(batch) {
        setBusy(true);
        setError('');
        setNotice('');
        try {
            const batchUrl = root.dataset.batchUrlTemplate.replace('__BATCH_ID__', batch.id);
            await loadBatch(batchUrl);
        } catch (requestError) {
            setError(requestError.message);
        } finally {
            setBusy(false);
        }
    }

    const activeRows = selected?.rows ?? [];

    return (
        <div className="react-import-page">
            <div className="page-heading heading-with-action">
                <div>
                    <div className="eyebrow">STAFF WORKFLOW · DAILY RECORDS</div>
                    <h1>Import daily sales</h1>
                    <p>Upload the Five CE POS CSV, review every row, then confirm to record sales and deduct stock.</p>
                </div>
                <a className="button button-secondary" href={root.dataset.templateUrl}>Download CSV template</a>
            </div>

            {error && <div className="alert alert-error" role="alert"><span>!</span>{error}</div>}
            {notice && <div className="alert alert-success" role="status"><span>✓</span>{notice}</div>}

            <div className="react-import-grid">
                <section className="panel form-panel">
                    <div className="panel-heading">
                        <div><h2>Upload sales file</h2><p>CSV or TXT · Up to 10 MB and 5,000 sales</p></div>
                        <span className="panel-step">01</span>
                    </div>
                    <form className="form-stack react-import-form" onSubmit={preview}>
                        <label className="react-file-picker">
                            <span className="react-upload-icon" aria-hidden="true">↑</span>
                            <strong>{file ? file.name : 'Choose your daily sales CSV'}</strong>
                            <small>{file ? `${(file.size / 1024).toFixed(1)} KB · Ready to preview` : 'Transaction_ID, Date, Time, Branch, Pump_ID, Nozzle_ID, Fuel_Grade, Liters, Price_Per_Liter, Total_Amount'}</small>
                            <input
                                type="file"
                                accept=".csv,.txt,text/csv,text/plain"
                                onChange={(event) => {
                                    setFile(event.target.files?.[0] ?? null);
                                    setError('');
                                }}
                            />
                        </label>
                        <div className="form-hint">
                            <span>i</span>
                            <span>Import one branch per file. Branch 1 is Manaoag; Branch 2 is San Fabian. CSV prices are recorded for imported transactions.</span>
                        </div>
                        <button className="button-wide flex min-h-11 w-full items-center justify-between rounded-xl bg-[#9f1239] px-4 py-2.5 text-sm font-bold text-white transition hover:bg-[#881337] disabled:cursor-not-allowed disabled:opacity-60" type="submit" disabled={busy || !file}>
                            <span className="react-button-label">{busy ? 'Please wait…' : 'Preview sales file'}</span>
                            <span aria-hidden="true">→</span>
                        </button>
                    </form>
                </section>

                <aside className="panel react-import-guide">
                    <div className="note-icon">✓</div>
                    <span className="eyebrow">SAFE IMPORT PROCESS</span>
                    <h3>Check before anything is recorded.</h3>
                    <p>FiveCETrack validates duplicate IDs, nozzle codes, prices, timestamps, and branch stock before allowing an import.</p>
                    <div className="note-divider" />
                    <ol>
                        <li><span>1</span>Upload the daily CSV file</li>
                        <li><span>2</span>Review row errors and totals</li>
                        <li><span>3</span>Confirm to post all valid sales</li>
                    </ol>
                </aside>
            </div>

            {selected && (
                <section className="panel table-panel react-import-preview">
                    <div className="panel-heading">
                        <div><h2>Preview: {selected.filename}</h2><p>{selected.status} · uploaded {dateTime(selected.created_at)}</p></div>
                        <div className="react-import-summary">
                            <span className="count-pill">{selected.row_count} rows</span>
                            <span className="react-valid-count">{selected.valid_row_count} valid</span>
                            <span className={selected.invalid_row_count ? 'react-invalid-count' : 'react-valid-count'}>
                                {selected.invalid_row_count} invalid
                            </span>
                        </div>
                    </div>
                    {activeRows.length ? (
                        <div className="table-scroll">
                            <table>
                                <thead>
                                    <tr><th>ROW</th><th>TRANSACTION</th><th>SALE TIME</th><th>BRANCH / PUMP</th><th>NOZZLE / FUEL</th><th>LITERS</th><th>PRICE / L</th><th>TOTAL</th><th>STATUS / ERRORS</th></tr>
                                </thead>
                                <tbody>
                                    {activeRows.map((row) => {
                                        const rowErrors = Array.isArray(row.errors) ? row.errors : [];
                                        return (
                                            <tr key={row.id ?? row.row_number}>
                                                <td>{row.row_number}</td>
                                                <td><strong>{row.source_transaction_id || '—'}</strong></td>
                                                <td>{dateTime(row.sold_at)}</td>
                                                <td>{row.branch_label || '—'}{row.pump_code ? <><br /><strong>{row.pump_code}</strong></> : null}</td>
                                                <td>{row.nozzle_code}{row.fuel_grade ? <><br /><small>{row.fuel_grade}</small></> : null}</td>
                                                <td>{numberValue(row.liters)} L</td>
                                                <td>{moneyValue(row.price_per_liter)}</td>
                                                <td>{moneyValue(row.total_amount)}</td>
                                                <td>
                                                    <span className={row.status === 'invalid' ? 'react-invalid-count' : 'react-valid-count'}>
                                                        {row.status}
                                                    </span>
                                                    {rowErrors.length > 0 && (
                                                        <ul className="react-row-errors">{rowErrors.map((message) => <li key={message}>{message}</li>)}</ul>
                                                    )}
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    ) : (
                        <div className="table-empty"><strong>No sales rows are available in this preview.</strong></div>
                    )}
                    <div className="react-import-confirm">
                        <p>{selected.status === 'imported'
                            ? 'This batch has been imported successfully and recorded in your branch inventory.'
                            : selected.canImport
                                ? 'All rows passed validation. Confirming will post the full batch to sales and inventory.'
                                : 'This batch cannot be imported yet. Correct the invalid rows in the source file and upload it again.'}</p>
                        <button className="inline-flex min-h-10 items-center justify-center rounded-xl bg-[#9f1239] px-4 py-2.5 text-sm font-bold text-white transition hover:bg-[#881337] disabled:cursor-not-allowed disabled:opacity-60" type="button" disabled={busy || !selected.canImport} onClick={confirmImport}>
                            {selected.status === 'imported' ? 'Import complete' : busy ? 'Processing…' : 'Confirm and import all sales'}
                        </button>
                    </div>
                </section>
            )}

            <section className="panel table-panel">
                <div className="panel-heading">
                    <div><h2>Recent import batches</h2><p>Previous uploads for this branch</p></div>
                    <button className="button button-secondary button-small" type="button" disabled={busy} onClick={() => loadBatches().catch((loadError) => setError(loadError.message))}>
                        Refresh
                    </button>
                </div>
                {batches.length ? (
                    <div className="table-scroll">
                        <table>
                            <thead><tr><th>FILE</th><th>UPLOADED</th><th>ROWS</th><th>STATUS</th><th /></tr></thead>
                            <tbody>
                                {batches.map((batch) => (
                                    <tr key={batch.id}>
                                        <td><strong>{batch.filename}</strong></td>
                                        <td>{dateTime(batch.created_at)}</td>
                                        <td>{batch.valid_row_count} valid · {batch.invalid_row_count} invalid</td>
                                        <td><span className={batch.status === 'imported' ? 'react-valid-count' : 'react-batch-status'}>{batch.status}</span></td>
                                        <td><button className="button button-secondary button-small" type="button" disabled={busy} onClick={() => selectBatch(batch)}>Review</button></td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                ) : (
                    <div className="table-empty"><span>⇧</span><strong>No imports yet</strong><p>After your first preview, the file and its result will appear here.</p></div>
                )}
            </section>
        </div>
    );
}

function numberValue(value) {
    return new Intl.NumberFormat('en-PH', { maximumFractionDigits: 3 }).format(Number(value ?? 0));
}

function moneyValue(value) {
    if (value === null || value === undefined || value === '') return '—';
    return new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(Number(value));
}

import React, { useEffect, useState } from 'react';
import { createRoot } from 'react-dom/client';

const number = (value, fractionDigits = 0) => new Intl.NumberFormat('en-PH', {
    maximumFractionDigits: fractionDigits,
    minimumFractionDigits: fractionDigits,
}).format(value);

function Dashboard({ root }) {
    const [dashboard, setDashboard] = useState(null);
    const [error, setError] = useState('');

    useEffect(() => {
        let cancelled = false;

        fetch(root.dataset.apiUrl, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then(async (response) => {
                if (!response.ok) {
                    throw new Error(`Dashboard data could not be loaded (HTTP ${response.status}).`);
                }

                return response.json();
            })
            .then((data) => {
                if (!cancelled) setDashboard(data);
            })
            .catch((requestError) => {
                if (!cancelled) setError(requestError.message);
            });

        return () => {
            cancelled = true;
        };
    }, [root]);

    if (error) {
        return <div className="alert alert-error" role="alert">{error}</div>;
    }

    if (!dashboard) {
        return <p className="table-empty" role="status">Loading fuel inventory…</p>;
    }

    const { inventory, recent_transactions: transactions, tanks, user } = dashboard;

    return (
        <>
            <div className="page-heading heading-with-action dashboard-heading">
                <div>
                    <div className="eyebrow">FIVE CE GAS STATION</div>
                    <h1>Dashboard</h1>
                    <p>Welcome back, {user.name}. Here’s your latest fuel inventory.</p>
                </div>
                <a className="button button-primary" href={root.dataset.setupUrl}>
                    <span>＋</span> Manage fuel setup
                </a>
            </div>

            <section className="dashboard-fuel-cards" id="fuel-inventory" aria-label="Fuel inventory summary">
                {inventory.length ? inventory.map((item, index) => (
                    <article className={`dashboard-fuel-card fuel-card-${index % 3}`} key={item.product}>
                        <div className="dashboard-fuel-icon" aria-hidden="true"><span>▰</span><i /></div>
                        <div className="dashboard-fuel-copy">
                            <span className="fuel-card-branch">{item.branch || 'BRANCH'}</span>
                            <h2>{item.product}</h2>
                            <strong>{number(item.stock_liters)} <small>L</small></strong>
                            <span className={`fuel-status ${item.stock_liters > 0 ? 'fuel-status-in-stock' : 'fuel-status-empty'}`}>
                                <i />{item.stock_liters > 0 ? 'In Stock' : 'Out of Stock'}
                            </span>
                        </div>
                        <div className="fuel-capacity-note">
                            <span>{item.tank_count} {item.tank_count === 1 ? 'tank' : 'tanks'}</span>
                            <span>Capacity {number(item.capacity_liters)} L</span>
                        </div>
                    </article>
                )) : (
                    <div className="dashboard-empty-fuel">
                        <span className="empty-icon">◉</span>
                        <div><strong>No fuel inventory yet</strong><p>Set up fuel products and tanks to see stock summaries here.</p></div>
                        <a className="button button-primary" href={root.dataset.setupUrl}>Set up station</a>
                    </div>
                )}
            </section>

            <div className="dashboard-content-grid">
                <section className="panel dashboard-chart-panel">
                    <div className="panel-heading dashboard-panel-heading">
                        <div><h2>Fuel Inventory Level</h2><p>Recorded stock compared with tank capacity</p></div>
                        <span className="chart-period"><i className="status-dot" /> Live stock ledger</span>
                    </div>
                    {tanks.length ? (
                        <>
                            <div className="chart-legend">
                                <span><i className="legend-current" /> Current stock</span>
                                <span><i className="legend-capacity" /> Tank capacity</span>
                            </div>
                            <div className="fuel-chart" role="img" aria-label="Fuel tank current stock compared with capacity">
                                <div className="chart-scale" aria-hidden="true">
                                    <span>100%</span><span>75%</span><span>50%</span><span>25%</span><span>0</span>
                                </div>
                                <div className="chart-main">
                                    <div className="chart-grid-lines" aria-hidden="true"><i /><i /><i /><i /><i /></div>
                                    <div className="chart-columns">
                                        {tanks.map((tank) => (
                                            <div
                                                className="chart-column"
                                                key={`${tank.branch}-${tank.label}`}
                                                title={`${tank.branch} · ${tank.label} · ${number(tank.stock)} of ${number(tank.capacity)} liters`}
                                            >
                                                <div className="chart-bars">
                                                    <span className="chart-bar chart-bar-stock" style={{ height: `${tank.stock_percentage}%` }} />
                                                    <span className="chart-bar chart-bar-capacity" style={{ height: '100%' }} />
                                                </div>
                                                <strong>{tank.label}</strong>
                                                <small>{tank.fuel}<br />{tank.branch}</small>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            </div>
                            <div className="chart-footnote">Current balances are calculated from recorded opening stock, approved deliveries, sales, and approved adjustments.</div>
                        </>
                    ) : (
                        <div className="table-empty dashboard-chart-empty">
                            <span>▥</span><strong>Inventory chart will appear here</strong>
                            <p>Once tanks and opening stock are set up, you can compare their current recorded balance with capacity.</p>
                        </div>
                    )}
                </section>

                <section className="panel dashboard-transactions-panel" id="transactions">
                    <div className="panel-heading dashboard-panel-heading">
                        <div><h2>Recent Transactions</h2><p>Latest recorded stock movements</p></div>
                    </div>
                    {transactions.length ? (
                        <>
                            <div className="table-scroll dashboard-table-scroll">
                                <table className="dashboard-transactions-table">
                                    <thead><tr><th>DATE</th><th>TYPE</th><th>FUEL</th><th>LITERS</th><th>AMOUNT</th></tr></thead>
                                    <tbody>
                                        {transactions.map((transaction, index) => (
                                            <tr key={`${transaction.date}-${transaction.time}-${index}`}>
                                                <td>{transaction.date}<small className="dashboard-time">{transaction.time}</small></td>
                                                <td><span className={`movement-type movement-type-${transaction.direction}`}>{transaction.type}</span></td>
                                                <td><strong>{transaction.fuel}</strong><small className="dashboard-time">{transaction.branch}</small></td>
                                                <td className={transaction.direction === 'out' ? 'movement-out' : 'movement-in'}>
                                                    {transaction.direction === 'out' ? '−' : '+'}{number(transaction.liters, 1)} L
                                                </td>
                                                <td>{transaction.amount === null ? '—' : `₱${number(transaction.amount, 2)}`}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                            <div className="dashboard-transactions-foot">
                                <span><i className="status-dot" /> {transactions.length} latest movements</span>
                            </div>
                        </>
                    ) : (
                        <div className="table-empty dashboard-transactions-empty">
                            <span>▤</span><strong>No stock transactions yet</strong>
                            <p>Sales, deliveries, and approved adjustments will appear here.</p>
                        </div>
                    )}
                </section>
            </div>

            <div className="dashboard-bottom-actions">
                <span><i className="status-dot" /> Stock balances reflect approved and recorded activity.</span>
                <div>
                    <a className="button button-secondary" href={root.dataset.salesUrl}>View sales</a>
                    <a className="button button-secondary" href={root.dataset.deliveriesUrl}>View deliveries</a>
                    <a className="button button-secondary" href={root.dataset.adjustmentsUrl}>Review stock adjustments</a>
                </div>
            </div>
        </>
    );
}

export function mountDashboard(rootElement) {
    createRoot(rootElement).render(<Dashboard root={rootElement} />);
}

// Local Configuration Architecture Routing Framework Mapping
const API_BASE = '../backend';
let localCatalogMatrix = [];
let shoppingCartMap = new Map();
let targetPaymentGateway = 'Cash';
let processedActiveTransactionJournal = null;
let settlementConfirmationResolver = null;

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

// Initial Dom Node Bindings Layout
document.addEventListener('DOMContentLoaded', async () => {
    const session = await guardPage(['pos.access']);
    if (!session) return; // guardPage() has already redirected away

    const cashierNameDisplay = document.getElementById('cashier-name-display');
    if (cashierNameDisplay) cashierNameDisplay.textContent = session.full_name || session.username;

    const adminNavBtn = document.getElementById('admin-nav-btn');
    if (adminNavBtn) {
        const canReachAdminDashboard = (session.permissions || []).some(
            p => ['system.users.manage', 'system.roles.manage', 'system.audit.view', 'system.settings.manage'].includes(p)
        );
        if (canReachAdminDashboard) adminNavBtn.classList.remove('hidden');
    }

    const refundsNavBtn = document.getElementById('refunds-nav-btn');
    if (refundsNavBtn && (session.permissions || []).includes('pos.refund')) {
        refundsNavBtn.classList.remove('hidden');
    }

    const restockNavBtn = document.getElementById('restock-nav-btn');
    if (restockNavBtn && (session.permissions || []).includes('restock.request')) {
        restockNavBtn.classList.remove('hidden');
    }

    const logoutBtn = document.getElementById('pos-logout-btn');
    if (logoutBtn) logoutBtn.addEventListener('click', logoutAndRedirect);

    initRealtimeClock();
    bootstrapSystemDatastream();
    setupReactiveEventListeners();
});

function initRealtimeClock() {
    const clockNode = document.getElementById('system-time');
    setInterval(() => {
        const d = new Date();
        clockNode.textContent = d.toLocaleTimeString('en-US', { hour12: true });
    }, 1000);
}

// REST Client Data Loader Pipeline
async function bootstrapSystemDatastream() {
    logMiddlewareMessage('SYSTEM', 'Initiating connection queries to persistence layer...');
    try {
        const response = await fetch(`${API_BASE}/products.php`);
        if (!response.ok) throw new Error(`HTTP network anomaly state: ${response.status}`);
        
        const rawPayload = await response.json();
        
        // Robust check: Extract the array if it's wrapped in an envelope object
        if (Array.isArray(rawPayload)) {
            localCatalogMatrix = rawPayload;
        } else if (rawPayload && Array.isArray(rawPayload.data)) {
            localCatalogMatrix = rawPayload.data;
        } else if (rawPayload && typeof rawPayload === 'object') {
            // Fallback: search for any array key inside the response object
            const foundArrayKey = Object.keys(rawPayload).find(key => Array.isArray(rawPayload[key]));
            if (foundArrayKey) {
                localCatalogMatrix = rawPayload[foundArrayKey];
            } else {
                throw new Error("No array found in product database response payload.");
            }
        } else {
            throw new Error("Invalid payload format received from database backend.");
        }

        logMiddlewareMessage('DATABASE', `SQLite transaction payload resolved. Synchronized ${localCatalogMatrix.length} entities successfully.`);
        
        renderCategoryFilterTabs();
        renderCatalogGridElements(localCatalogMatrix);
    } catch (err) {
        logMiddlewareMessage('CRITICAL', `Pipeline breakdown error: ${err.message}`);
        document.getElementById('product-grid').innerHTML = `
            <div class="col-span-full py-8 text-center text-rose-400 bg-rose-950/20 border border-rose-900/30 rounded-xl">
                ⚠️ [DATA INTEGRATION CORRUPTION ALERT]<br>${escapeHtml(err.message)}
            </div>`;
    }
}

// Interface Generation Layer Subcomponents
function renderCategoryFilterTabs() {
    const categories = ['All', ...new Set(localCatalogMatrix.map(p => p.category))];
    const targetNode = document.getElementById('category-pills');
    
    targetNode.innerHTML = categories.map((cat, idx) => `
        <button onclick="filterCatalogStream('${escapeHtml(cat)}', this)" class="category-tab-btn px-3 py-1.5 rounded-lg text-xs font-medium border transition-all ${idx === 0 ? 'bg-emerald-600 text-white border-emerald-500 shadow-md shadow-emerald-950/50' : 'bg-gray-900 text-gray-400 border-gray-800 hover:text-white hover:border-gray-700'}">
            ${escapeHtml(cat)}
        </button>
    `).join('');
}

function renderCatalogGridElements(productsList) {
    const gridNode = document.getElementById('product-grid');
    if (productsList.length === 0) {
        gridNode.innerHTML = `<div class="col-span-full py-12 text-center text-gray-500 font-medium">No matches matching indices detected.</div>`;
        return;
    }

    gridNode.innerHTML = productsList.map(item => {
        const qty = parseInt(item.stock_quantity);
        const isOutOfStock = qty <= 0;
        
        return `
            <div onclick="${isOutOfStock ? '' : `appendCartItemBySku('${escapeHtml(item.sku)}')`}" 
                 class="group relative flex flex-col justify-between p-4 rounded-xl border bg-gray-900 transition-all duration-200 select-none ${isOutOfStock ? 'opacity-40 border-gray-900 cursor-not-allowed' : 'border-gray-800 hover:border-gray-700 hover:bg-gray-850 cursor-pointer hover:shadow-lg transform hover:-translate-y-0.5'}">
                
                <div>
                    <div class="flex items-start justify-between mb-1">
                        <h3 class="text-sm font-bold text-white group-hover:text-emerald-400 transition-colors line-clamp-2">${escapeHtml(item.name)}</h3>
                    </div>
                    <span class="text-[10px] font-mono tracking-wider text-gray-500 uppercase">${escapeHtml(item.sku)}</span>
                </div>

                <div class="mt-4 flex items-baseline justify-between">
                    <span class="text-md font-extrabold text-white">₱${parseFloat(item.price).toFixed(2)}</span>
                    <span class="text-xs font-mono px-2 py-0.5 rounded ${isOutOfStock ? 'bg-rose-950 text-rose-400 border border-rose-900/30' : qty < 5 ? 'bg-amber-950 text-amber-400 border border-amber-900/30' : 'bg-gray-950 text-gray-400'}">
                        ${isOutOfStock ? 'OUT OF STOCK' : `${qty} left`}
                    </span>
                </div>
            </div>`;
    }).join('');
}

function filterCatalogStream(category, buttonEl) {
    document.querySelectorAll('.category-tab-btn').forEach(b => {
        b.className = "category-tab-btn px-3 py-1.5 rounded-lg text-xs font-medium border bg-gray-900 text-gray-400 border-gray-800 hover:text-white hover:border-gray-700 transition-all";
    });
    buttonEl.className = "category-tab-btn px-3 py-1.5 rounded-lg text-xs font-medium border bg-emerald-600 text-white border-emerald-500 shadow-md shadow-emerald-950/50 transition-all";

    const filtered = category === 'All' ? localCatalogMatrix : localCatalogMatrix.filter(p => p.category === category);
    renderCatalogGridElements(filtered);
}

// Real-Time Stateful Invariant Computations 
function appendCartItemBySku(sku) {
    const product = localCatalogMatrix.find(p => p.sku === sku);
    if (!product) return;

    const currentlyInCart = shoppingCartMap.get(sku) || 0;
    if (currentlyInCart >= parseInt(product.stock_quantity)) {
        logMiddlewareMessage('INVENTORY', `Allocation structural boundary alert: ${product.name} limits hit.`);
        return;
    }

    shoppingCartMap.set(sku, currentlyInCart + 1);
    logMiddlewareMessage('EVENT_BUS', `Cart Mutation event dispatched directly: queued item SKU [${sku}]`);
    synchronizeCartStateUI();
}

function adjustCartQuantity(sku, delta) {
    const current = shoppingCartMap.get(sku) || 0;
    const product = localCatalogMatrix.find(p => p.sku === sku);
    const newQty = current + delta;

    if (newQty <= 0) {
        shoppingCartMap.delete(sku);
    } else if (newQty > parseInt(product.stock_quantity)) {
        logMiddlewareMessage('INVENTORY', `System allocation boundary exception on [${sku}]. Overflow restricted.`);
        return;
    } else {
        shoppingCartMap.set(sku, newQty);
    }
    synchronizeCartStateUI();
}

function synchronizeCartStateUI() {
    const cartWrapper = document.getElementById('cart-container');
    const badge = document.getElementById('cart-count-badge');
    
    if (shoppingCartMap.size === 0) {
        cartWrapper.innerHTML = `
            <div class="flex flex-col items-center justify-center h-full text-gray-500 text-center space-y-2">
                <span class="text-3xl">🛒</span>
                <p class="text-sm">Transaction ledger empty.<br>Tap catalog components to queue entries.</p>
            </div>`;
        badge.textContent = "0 Items";
        updateCalculatedPricingSummary(0);
        document.getElementById('pay-now-btn').disabled = true;
        const clearCartBtn = document.getElementById('clear-cart-btn');
        if (clearCartBtn) clearCartBtn.disabled = true;
        return;
    }

    let itemAccumulatorCount = 0;
    let currencySubtotalSum = 0;
    let htmlStringBuilder = "";

    shoppingCartMap.forEach((qty, sku) => {
        const item = localCatalogMatrix.find(p => p.sku === sku);
        if (!item) return;

        itemAccumulatorCount += qty;
        currencySubtotalSum += (parseFloat(item.price) * qty);

        htmlStringBuilder += `
            <div class="flex items-center justify-between p-3 rounded-xl bg-gray-950 border border-gray-850">
                <div class="flex-1 pr-2">
                    <h4 class="text-xs font-bold text-white line-clamp-1">${escapeHtml(item.name)}</h4>
                    <span class="text-[10px] font-mono text-gray-500">₱${parseFloat(item.price).toFixed(2)} × ${qty}</span>
                </div>
                <div class="flex items-center space-x-1.5 bg-gray-900 border border-gray-800 rounded-lg p-0.5">
                    <button onclick="adjustCartQuantity('${escapeHtml(sku)}', -1)" class="w-6 h-6 rounded flex items-center justify-center text-xs text-gray-400 hover:text-white hover:bg-gray-800 transition-colors font-bold">-</button>
                    <span class="font-mono text-xs font-bold text-white px-1 min-w-[16px] text-center">${qty}</span>
                    <button onclick="adjustCartQuantity('${escapeHtml(sku)}', 1)" class="w-6 h-6 rounded flex items-center justify-center text-xs text-gray-400 hover:text-white hover:bg-gray-800 transition-colors font-bold">+</button>
                </div>
            </div>`;
    });

    badge.textContent = `${itemAccumulatorCount} Item${itemAccumulatorCount > 1 ? 's' : ''}`;
    cartWrapper.innerHTML = htmlStringBuilder;
    updateCalculatedPricingSummary(currencySubtotalSum);
    const payBtn = document.getElementById('pay-now-btn');
    payBtn.disabled = false;
    const clearCartBtn = document.getElementById('clear-cart-btn');
    if (clearCartBtn) clearCartBtn.disabled = false;
    // Show cash tendered row when Cash is active and cart is non-empty
    const cashRow = document.getElementById('cash-tendered-row');
    if (cashRow) cashRow.classList.toggle('hidden', targetPaymentGateway !== 'Cash');
    updateCashChange();
}

function getCurrentDiscountType() {
    const el = document.getElementById('discount-type-select');
    return el ? el.value : 'None';
}

function updateCalculatedPricingSummary(subtotal) {
    const discountType = getCurrentDiscountType();
    const isDiscounted = discountType !== 'None';
    const discount = isDiscounted ? subtotal * 0.20 : 0;
    const taxableAmount = subtotal - discount;
    const calculatedVAT = isDiscounted ? 0 : taxableAmount * 0.12;
    const aggregateGrossTotal = taxableAmount + calculatedVAT;

    document.getElementById('summary-subtotal').textContent = `₱${subtotal.toFixed(2)}`;

    const discountRow = document.getElementById('summary-discount-row');
    if (discountRow) {
        discountRow.classList.toggle('hidden', !isDiscounted);
        discountRow.classList.toggle('flex', isDiscounted);
    }
    const discountEl = document.getElementById('summary-discount');
    if (discountEl) discountEl.textContent = `-₱${discount.toFixed(2)}`;

    const taxLabelEl = document.getElementById('summary-tax-label');
    if (taxLabelEl) taxLabelEl.textContent = isDiscounted ? 'VAT (Exempt):' : 'VAT (12%):';

    document.getElementById('summary-tax').textContent = `₱${calculatedVAT.toFixed(2)}`;
    document.getElementById('summary-total').textContent = `₱${aggregateGrossTotal.toFixed(2)}`;
    updateCashChange();
}

function updateCashChange() {
    const totalEl = document.getElementById('summary-total');
    const total = parseFloat(totalEl.textContent.replace('₱', '').replace(/,/g, '')) || 0;
    const tendered = parseFloat(document.getElementById('cash-tendered-input')?.value) || 0;
    const change = tendered - total;
    const changeEl = document.getElementById('summary-change');
    if (changeEl) {
        changeEl.textContent = `₱${Math.max(change, 0).toFixed(2)}`;
        changeEl.className = change < 0 ? 'font-mono text-rose-400' : 'font-mono text-amber-400';
    }
    // Disable pay button if cash is selected and tendered is insufficient
    if (targetPaymentGateway === 'Cash' && shoppingCartMap.size > 0 && total > 0) {
        const payBtn = document.getElementById('pay-now-btn');
        if (payBtn) {
            payBtn.disabled = tendered < total;
        }
    }
}

// Settlement API Pipeline Orchestration Middleware Integration
function setupReactiveEventListeners() {
    // Dynamic payment button routing layout selection
    document.querySelectorAll('.payment-method-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.payment-method-btn').forEach(b => b.classList.remove('active-gateway'));
            this.classList.add('active-gateway');
            targetPaymentGateway = this.getAttribute('data-method');
            logMiddlewareMessage('GATEWAY', `Payment engine switched downstream provider endpoint -> ${targetPaymentGateway}`);
            const cashRow = document.getElementById('cash-tendered-row');
            if (cashRow) cashRow.classList.toggle('hidden', targetPaymentGateway !== 'Cash');
            updateCashChange();
        });
    });

    const tenderedInput = document.getElementById('cash-tendered-input');
    if (tenderedInput) tenderedInput.addEventListener('input', updateCashChange);

    const discountTypeSelect = document.getElementById('discount-type-select');
    const discountIdInput = document.getElementById('discount-id-input');
    if (discountTypeSelect) {
        discountTypeSelect.addEventListener('change', () => {
            const isDiscounted = discountTypeSelect.value !== 'None';
            if (discountIdInput) {
                discountIdInput.classList.toggle('hidden', !isDiscounted);
                if (!isDiscounted) discountIdInput.value = '';
            }
            synchronizeCartStateUI();
            logMiddlewareMessage('POS', isDiscounted
                ? `Senior/PWD discount applied: ${discountTypeSelect.value} (20% off, VAT-exempt).`
                : 'Senior/PWD discount cleared.');
        });
    }

    setupRefundModal();

    // Handle interactive full checkout payloads
    const payNowBtn = document.getElementById('pay-now-btn');
    if (payNowBtn) {
        payNowBtn.addEventListener('click', executeTransactionCheckoutPipeline);
    }

    // Confirmation modal lifecycle: the checkout pipeline waits for one of these
    // explicit operator decisions before it can dispatch a backend transaction.
    const confirmationModal = document.getElementById('custom-confirm-modal');
    const cancelBtn = document.getElementById('modal-cancel-btn');
    const confirmBtn = document.getElementById('modal-confirm-btn');

    if (cancelBtn && confirmationModal) {
        cancelBtn.addEventListener('click', () => {
            resolveSettlementConfirmation(false);
        });
    }
    if (confirmBtn && confirmationModal) {
        confirmBtn.addEventListener('click', () => {
            resolveSettlementConfirmation(true);
        });
    }
    if (confirmationModal) {
        confirmationModal.addEventListener('click', event => {
            if (event.target === confirmationModal) resolveSettlementConfirmation(false);
        });
    }
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape') resolveSettlementConfirmation(false);
    });
    
    // Bind hardware simulated printer adapter trigger
    document.getElementById('print-hardware-btn').addEventListener('click', executeHardwarePrintDialog);
    document.getElementById('copy-order-id-btn').addEventListener('click', () => {
        const orderId = document.getElementById('receipt-id').textContent;
        navigator.clipboard.writeText(orderId).then(() => {
            const btn = document.getElementById('copy-order-id-btn');
            btn.textContent = '✓ Copied!';
            setTimeout(() => { btn.textContent = '📋 Copy ID'; }, 2000);
        });
    });
    document.getElementById('close-receipt-btn').addEventListener('click', () => {
        document.getElementById('receipt-modal').classList.add('hidden');
        processedActiveTransactionJournal = null;
        logMiddlewareMessage('SYSTEM', 'Digital receipt acknowledged. New order workspace initialized.');
    });

    // Search filtration mapping dynamic parsing engine
    document.getElementById('search-input').addEventListener('input', (e) => {
        const query = e.target.value.toLowerCase().trim();
        const matches = localCatalogMatrix.filter(p => p.name.toLowerCase().includes(query) || p.sku.toLowerCase().includes(query));
        renderCatalogGridElements(matches);
    });

    document.getElementById('search-input').addEventListener('keydown', (e) => {
        if (e.key !== 'Enter') return;
        const query = e.target.value.trim();
        const match = localCatalogMatrix.find(p => p.sku.toLowerCase() === query.toLowerCase());
        if (match) {
            e.preventDefault();
            appendCartItemBySku(match.sku);
            e.target.value = '';
            renderCatalogGridElements(localCatalogMatrix);
            logMiddlewareMessage('EVENT_BUS', `Barcode scan: SKU [${match.sku}] added to cart.`);
        }
    });

    const clearCartBtn = document.getElementById('clear-cart-btn');
    if (clearCartBtn) {
        clearCartBtn.addEventListener('click', () => {
            if (shoppingCartMap.size === 0) return;
            document.getElementById('void-confirm-modal').classList.remove('hidden');
        });
    }

    const voidModal = document.getElementById('void-confirm-modal');
    document.getElementById('void-cancel-btn').addEventListener('click', () => {
        voidModal.classList.add('hidden');
    });
    document.getElementById('void-confirm-btn').addEventListener('click', () => {
        voidModal.classList.add('hidden');
        shoppingCartMap.clear();
        logMiddlewareMessage('SYSTEM', 'Order voided by operator.');
        synchronizeCartStateUI();
    });
    voidModal.addEventListener('click', (e) => {
        if (e.target === voidModal) voidModal.classList.add('hidden');
    });
}

async function executeTransactionCheckoutPipeline() {
    if (shoppingCartMap.size === 0) return;

    const discountTypeSelectEl = document.getElementById('discount-type-select');
    const discountIdInputEl = document.getElementById('discount-id-input');
    if (discountTypeSelectEl && discountTypeSelectEl.value !== 'None'
        && discountIdInputEl && discountIdInputEl.value.trim() === '') {
        logMiddlewareMessage('REJECTED', 'Senior/PWD discount selected but no qualifying ID number was entered.');
        discountIdInputEl.focus();
        return;
    }

    const checkoutPayBtn = document.getElementById('pay-now-btn');
    checkoutPayBtn.disabled = true;

    const settlementConfirmed = await requestSettlementConfirmation();
    if (!settlementConfirmed) {
        logMiddlewareMessage('SYSTEM', 'Transaction aborted by operator constraint.');
        checkoutPayBtn.disabled = false;
        return;
    }
    
    // Convert memory bindings data map state structure to standardized transfer layer payload arrays
    const collectionItems = [];
    shoppingCartMap.forEach((qty, sku) => {
        collectionItems.push({ sku: sku, qty: qty });
    });

    const discountTypeSelect = document.getElementById('discount-type-select');
    const discountIdInput = document.getElementById('discount-id-input');

    const integrationWirePayload = {
        paymentMethod: targetPaymentGateway,
        items: collectionItems,
        discountType: discountTypeSelect ? discountTypeSelect.value : 'None',
        discountIdNumber: discountIdInput ? discountIdInput.value.trim() : '',
    };

    logMiddlewareMessage('EAI_BUS', 'Packing message envelope. Dispatching transactional items array payload downstream...');
    
    try {
        const response = await fetch(`${API_BASE}/event_bus.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(integrationWirePayload)
        });

        const outcomeText = await response.text();
        let transactionalResultObj = null;
        try {
            transactionalResultObj = JSON.parse(outcomeText);
        } catch(e) {
            // Some integrations return a plain-text acknowledgement.  It is handled
            // below as long as it is an otherwise successful HTTP response.
        }

        const responseSignalsSuccess =
            response.ok && (
                /\bsuccess\b/i.test(outcomeText) ||
                transactionalResultObj?.status === 'success' ||
                transactionalResultObj?.success === true ||
                transactionalResultObj?.ok === true ||
                transactionalResultObj?.data?.status === 'success' ||
                transactionalResultObj?.data?.success === true ||
                transactionalResultObj?.data?.ok === true
            );

        if (responseSignalsSuccess) {
            // Dispatch each server-side event trail entry to the EAI monitor console.
            // Falls back to two hardcoded summary lines only when eventTrail is absent
            // (e.g. a non-standard proxy response that still signals success).
            if (Array.isArray(transactionalResultObj?.eventTrail) && transactionalResultObj.eventTrail.length > 0) {
                dispatchEventTrailToMonitorConsole(transactionalResultObj.eventTrail);
            } else {
                logMiddlewareMessage('EAI_BUS', 'SUCCESS response packet returned from message broker ledger.');
                logMiddlewareMessage('ERP_INTEGRATION', 'Event Hook: transaction logs committed directly to backend sales tables.');
            }
            
            const displayedTotal = parseFloat(
                document.getElementById('summary-total')?.textContent.replace('₱', '').replace(/,/g, '')
            ) || 0;
            const cashTendered = targetPaymentGateway === 'Cash'
                ? (parseFloat(document.getElementById('cash-tendered-input')?.value) || 0)
                : 0;

            processedActiveTransactionJournal = {
                journalId: transactionalResultObj.orderId,
                timestamp: new Date().toLocaleString('en-US', { hour12: true }),
                paymentMethod: targetPaymentGateway,
                totalDue: displayedTotal,
                cashTendered,
                changeDue: targetPaymentGateway === 'Cash' ? Math.max(cashTendered - displayedTotal, 0) : 0,
                items: collectionItems.map(item => {
                    const originalObj = localCatalogMatrix.find(p => p.sku === item.sku);
                    return {
                        name: originalObj.name,
                        sku: item.sku,
                        qty: item.qty,
                        price: parseFloat(originalObj.price)
                    };
                })
            };

            logMiddlewareMessage('SYSTEM', 'POS outbox local buffer updated. Outbound layout engine ready.');
            
            // Re-fetch backend products mapping metrics to maintain live persistence representation views dynamically
            await bootstrapSystemDatastream();
            shoppingCartMap.clear();
            const tenderedInput = document.getElementById('cash-tendered-input');
            if (tenderedInput) tenderedInput.value = '';
            const discountTypeSelectReset = document.getElementById('discount-type-select');
            const discountIdInputReset = document.getElementById('discount-id-input');
            if (discountTypeSelectReset) discountTypeSelectReset.value = 'None';
            if (discountIdInputReset) { discountIdInputReset.value = ''; discountIdInputReset.classList.add('hidden'); }
            synchronizeCartStateUI();

            // Present the completed transaction on screen; printing remains an
            // explicit operator action from the digital receipt modal.
            showDigitalReceipt(processedActiveTransactionJournal);
        } else {
            throw new Error(
                transactionalResultObj?.error ||
                transactionalResultObj?.message ||
                `Checkout request failed (HTTP ${response.status}).`
            );
        }
    } catch(err) {
        logMiddlewareMessage('REJECTED', `Enterprise routing layer failure error: ${err.message}`);
        checkoutPayBtn.disabled = false;
    }
}

function requestSettlementConfirmation() {
    const modal = document.getElementById('custom-confirm-modal');
    const confirmButton = document.getElementById('modal-confirm-btn');
    if (!modal || !confirmButton) {
        logMiddlewareMessage('REJECTED', 'Confirmation modal components are unavailable.');
        return Promise.resolve(false);
    }

    // Build item breakdown
    const itemsList = document.getElementById('confirm-items-list');
    itemsList.replaceChildren();
    let subtotal = 0;
    shoppingCartMap.forEach((qty, sku) => {
        const product = localCatalogMatrix.find(p => p.sku === sku);
        if (!product) return;
        const lineTotal = parseFloat(product.price) * qty;
        subtotal += lineTotal;
        const row = document.createElement('div');
        row.className = 'flex justify-between text-xs';
        const label = document.createElement('span');
        label.className = 'text-slate-300 truncate pr-2';
        label.textContent = `${product.name} × ${qty}`;
        const amount = document.createElement('span');
        amount.className = 'font-mono text-slate-400 shrink-0';
        amount.textContent = `₱${lineTotal.toFixed(2)}`;
        row.append(label, amount);
        itemsList.append(row);
    });

    const vat = subtotal * 0.12;
    const total = subtotal + vat;

    const paymentIcons = { Cash: '💵', Card: '💳', Wallet: '📱' };
    document.getElementById('confirm-subtotal').textContent = `₱${subtotal.toFixed(2)}`;
    document.getElementById('confirm-vat').textContent = `₱${vat.toFixed(2)}`;
    document.getElementById('confirm-total').textContent = `₱${total.toFixed(2)}`;
    document.getElementById('confirm-payment-method').textContent =
        `${paymentIcons[targetPaymentGateway] || ''} ${targetPaymentGateway}`;

    // Disable confirm for 2 seconds to prevent accidental tap
    confirmButton.disabled = true;
    confirmButton.textContent = 'Confirm (2)';
    let countdown = 2;
    const timer = setInterval(() => {
        countdown--;
        if (countdown <= 0) {
            clearInterval(timer);
            confirmButton.disabled = false;
            confirmButton.textContent = 'Confirm';
        } else {
            confirmButton.textContent = `Confirm (${countdown})`;
        }
    }, 1000);

    modal.classList.remove('hidden');

    return new Promise(resolve => {
        settlementConfirmationResolver = resolve;
    });
}

function resolveSettlementConfirmation(confirmed) {
    const modal = document.getElementById('custom-confirm-modal');
    if (modal) modal.classList.add('hidden');
    const resolve = settlementConfirmationResolver;
    settlementConfirmationResolver = null;
    if (resolve) resolve(confirmed);
}

function showDigitalReceipt(transaction) {
    const receiptList = document.getElementById('receipt-items-list');
    const subtotal = transaction.items.reduce((sum, item) => sum + (item.price * item.qty), 0);
    const vat = subtotal * 0.12;
    const total = subtotal + vat;

    receiptList.replaceChildren(...transaction.items.map(item => {
        const row = document.createElement('div');
        row.className = 'flex justify-between gap-3';

        const itemLabel = document.createElement('div');
        itemLabel.className = 'min-w-0';
        const nameEl = document.createElement('div');
        nameEl.className = 'text-slate-300';
        nameEl.textContent = item.name;
        const skuEl = document.createElement('div');
        skuEl.className = 'text-[10px] text-slate-600';
        skuEl.textContent = item.sku;
        itemLabel.append(nameEl, skuEl);

        const itemAmount = document.createElement('span');
        itemAmount.className = 'shrink-0 text-slate-400 self-center';
        itemAmount.textContent = `${item.qty} × ₱${item.price.toFixed(2)}`;

        row.append(itemLabel, itemAmount);
        return row;
    }));

    // Cash tendered / change are captured before the checkout form is reset.
    const tendered = Number(transaction.cashTendered || 0);
    const changeDue = Number(transaction.changeDue || 0);
    const cashRow = document.getElementById('receipt-cash-row');
    if (transaction.paymentMethod === 'Cash' && tendered > 0) {
        document.getElementById('receipt-tendered').textContent = `₱${tendered.toFixed(2)}`;
        document.getElementById('receipt-change').textContent = `₱${changeDue.toFixed(2)}`;
        cashRow.classList.remove('hidden');
    } else {
        cashRow.classList.add('hidden');
    }

    document.getElementById('receipt-date').textContent = `Date: ${transaction.timestamp}`;
    document.getElementById('receipt-id').textContent = transaction.journalId;
    document.getElementById('receipt-subtotal').textContent = `₱${subtotal.toFixed(2)}`;
    document.getElementById('receipt-vat').textContent = `₱${vat.toFixed(2)}`;
    document.getElementById('receipt-total').textContent = `₱${total.toFixed(2)}`;
    document.getElementById('receipt-payment-method').textContent = transaction.paymentMethod;
    document.getElementById('receipt-modal').classList.remove('hidden');
}

function executeHardwarePrintDialog() {
    if (!processedActiveTransactionJournal) return;
    
    logMiddlewareMessage('ERP_INTEGRATION', `Exporting text stream metadata file format stream map bound layout to physical peripheral...`);
    
    // Compute print line elements variables calculations 
    let grossAccumulator = 0;
    const printLineRows = processedActiveTransactionJournal.items.map(item => {
        const extensionTotal = item.price * item.qty;
        grossAccumulator += extensionTotal;
        return `${item.sku}\n${item.name.substring(0,18).padEnd(18)} x${item.qty}   ₱${extensionTotal.toFixed(2).padStart(7)}`;
    }).join('\n');

    const tax12 = grossAccumulator * 0.12;
    const netTotal = grossAccumulator + tax12;

    const receiptMarkupText = `
--------------------------------
    FRESHMART ENTERPRISE POS    
    Store Branch #1402, Cavite   
--------------------------------
Journal ID: ${processedActiveTransactionJournal.journalId}
Timestamp:  ${processedActiveTransactionJournal.timestamp}
Gateway:    ${processedActiveTransactionJournal.paymentMethod}
--------------------------------
ITEMS:
${printLineRows}
--------------------------------
Subtotal:              ₱${grossAccumulator.toFixed(2).padStart(8)}
VAT Tax (12%):         ₱${tax12.toFixed(2).padStart(8)}
TOTAL BILL AMOUNT:     ₱${netTotal.toFixed(2).padStart(8)}
${processedActiveTransactionJournal.paymentMethod === 'Cash' ? `CASH TENDERED:         ₱${Number(processedActiveTransactionJournal.cashTendered || 0).toFixed(2).padStart(8)}
CHANGE:                ₱${Number(processedActiveTransactionJournal.changeDue || 0).toFixed(2).padStart(8)}
` : ''}--------------------------------
    TRANSACTION STATUS: PAID    
  Ledger Synchronization Valid  
 Thank you for buying local! 🍏 
--------------------------------
    `;

    const overlayPrintAreaContainer = document.getElementById('receipt-print-area');
    overlayPrintAreaContainer.textContent = receiptMarkupText;
    
    // Trigger OS client system native printing stream
    window.print();
    
    logMiddlewareMessage('EAI_BUS', `Print thread terminated cleanly. Transaction lifecycle finalized.`);
    
    // Auto-close receipt modal and reset state after printing
    document.getElementById('receipt-modal').classList.add('hidden');
    processedActiveTransactionJournal = null;
}

// EAI Event Trail Dispatcher — maps server-side eventTrail entries to the monitor console.
// Each entry type is routed to the appropriate subservice key and a human-readable message.
function dispatchEventTrailToMonitorConsole(eventTrailEntries) {
    const eventTrailDispatchRoutingMatrix = {
        OrderReceived: (payload) => [
            'EAI_BUS',
            `OrderReceived — ${payload.lineCount} line item(s), payment gateway: ${payload.paymentMethod}`,
        ],
        StockValidated: (payload) => [
            'INVENTORY',
            `StockValidated — ${payload.lineCount} SKU(s) cleared pre-deduction inventory check.`,
        ],
        StockDeducted: (payload) => [
            'INVENTORY',
            `StockDeducted — ${payload.items?.length ?? 0} SKU(s) decremented in IMS persistence layer.`,
        ],
        InventoryLow: (payload) => [
            'INVENTORY',
            `InventoryLow — SKU [${payload.sku}] at ${payload.remaining} unit(s) remaining. Reorder threshold breached.`,
        ],
        DiscountApplied: (payload) => [
            'POS',
            `DiscountApplied — ${payload.discountType} (${(payload.discountRate * 100).toFixed(0)}% off, ₱${Number(payload.amount).toFixed(2)}), VAT-exempt.`,
        ],
        PurchaseOrderCreated: (payload) => [
            'ERP_INTEGRATION',
            `PurchaseOrderCreated — PO #${payload.purchaseOrderId} opened for SKU [${payload.sku}], qty: ${payload.quantityOrdered} units.`,
        ],
        LedgerPosted: (payload) => [
            'ERP_INTEGRATION',
            `LedgerPosted — Order [${payload.orderId}] committed to sales_ledger (${payload.rows} row(s)).`,
        ],
        PaymentConfirmed: (payload) => [
            'EAI_BUS',
            `PaymentConfirmed — Order [${payload.orderId}] settled via ${payload.method}. Total: ₱${Number(payload.amount).toFixed(2)}.`,
        ],
    };

    eventTrailEntries.forEach(trailEntry => {
        const dispatchRouteResolver = eventTrailDispatchRoutingMatrix[trailEntry.type];
        if (dispatchRouteResolver) {
            const [subserviceKey, informationalMessage] = dispatchRouteResolver(trailEntry.payload ?? {});
            logMiddlewareMessage(subserviceKey, informationalMessage);
        } else {
            logMiddlewareMessage('EAI_BUS', `[${trailEntry.type}] — unregistered event type received from integration bus.`);
        }
    });
}

function logMiddlewareMessage(subserviceKey, informationalString) {
    const panelNode = document.getElementById('console-logs');
    if (!panelNode) return;

    const stamp = new Date().toLocaleTimeString('en-US', { hour12: true });
    let tokenColorAccent = "text-blue-400";

    if (subserviceKey === 'DATABASE') tokenColorAccent = "text-purple-400";
    if (subserviceKey === 'EAI_BUS') tokenColorAccent = "text-emerald-400";
    if (subserviceKey === 'INVENTORY') tokenColorAccent = "text-amber-400";
    if (subserviceKey === 'CRITICAL' || subserviceKey === 'REJECTED') tokenColorAccent = "text-rose-500 font-bold";
    if (subserviceKey === 'ERP_INTEGRATION') tokenColorAccent = "text-cyan-400";
    if (subserviceKey === 'POS') tokenColorAccent = "text-orange-400";

    panelNode.innerHTML += `<div><span class="text-gray-600">[${escapeHtml(stamp)}]</span> <span class="${tokenColorAccent}">[${escapeHtml(subserviceKey)}]</span> <span class="text-gray-300">${escapeHtml(informationalString)}</span></div>`;
    panelNode.scrollTop = panelNode.scrollHeight;
}

// ── POS REFUNDS ──────────────────────────────────────────────────────────────
// Looks up an order (via sales_report.php's single-order branch, which
// pos.refund can use even without sales.view), cross-references refunds.php
// for what's already been refunded on that order, and lets the cashier refund
// any remaining quantity per line item. Submits to process_refund.php, which
// re-validates everything server-side — this modal is just a convenience UI,
// not the source of truth for what's refundable.
function setupRefundModal() {
    const modal        = document.getElementById('refund-modal');
    const navBtn        = document.getElementById('refunds-nav-btn');
    const orderIdInput  = document.getElementById('refund-order-id-input');
    const lookupBtn     = document.getElementById('refund-lookup-btn');
    const lookupError   = document.getElementById('refund-lookup-error');
    const linesContainer = document.getElementById('refund-lines-container');
    const linesList     = document.getElementById('refund-lines-list');
    const reasonInput   = document.getElementById('refund-reason-input');
    const submitError   = document.getElementById('refund-submit-error');
    const submitBtn     = document.getElementById('refund-submit-btn');
    const closeBtn      = document.getElementById('refund-modal-close');

    if (!modal || !navBtn) return;

    let currentOrderId = null;
    let refundableLines = []; // [{sku, name, remaining}]

    function resetModal() {
        orderIdInput.value = '';
        lookupError.classList.add('hidden');
        linesContainer.classList.add('hidden');
        submitError.classList.add('hidden');
        submitBtn.classList.add('hidden');
        linesList.innerHTML = '';
        reasonInput.value = '';
        currentOrderId = null;
        refundableLines = [];
    }

    navBtn.addEventListener('click', () => {
        resetModal();
        modal.classList.remove('hidden');
    });
    closeBtn.addEventListener('click', () => modal.classList.add('hidden'));
    modal.addEventListener('click', (e) => { if (e.target === modal) modal.classList.add('hidden'); });

    async function lookupOrder() {
        lookupError.classList.add('hidden');
        linesContainer.classList.add('hidden');
        submitBtn.classList.add('hidden');
        const orderId = orderIdInput.value.trim();
        if (!orderId) {
            lookupError.textContent = 'Enter an order ID.';
            lookupError.classList.remove('hidden');
            return;
        }

        try {
            const [saleRes, refundRes] = await Promise.all([
                fetch(`${API_BASE}/sales_report.php?order_id=${encodeURIComponent(orderId)}`, { credentials: 'same-origin' }),
                fetch(`${API_BASE}/refunds.php?order_id=${encodeURIComponent(orderId)}`, { credentials: 'same-origin' }),
            ]);
            const sale = await saleRes.json();
            if (!saleRes.ok || !sale.ok) throw new Error(sale.error || 'Order not found.');
            const refundData = await refundRes.json();
            const alreadyRefunded = {}; // sku -> qty
            if (refundRes.ok && refundData.ok) {
                (refundData.refunds || []).forEach(r => {
                    alreadyRefunded[r.item_sku] = (alreadyRefunded[r.item_sku] || 0) + Number(r.quantity_refunded);
                });
            }

            currentOrderId = orderId;
            refundableLines = sale.lines.map(line => ({
                sku: line.sku,
                name: line.name,
                sold: line.quantity_sold,
                remaining: line.quantity_sold - (alreadyRefunded[line.sku] || 0),
            })).filter(line => line.remaining > 0);

            if (refundableLines.length === 0) {
                lookupError.textContent = 'Every item on this order has already been fully refunded.';
                lookupError.classList.remove('hidden');
                return;
            }

            linesList.innerHTML = '';
            refundableLines.forEach(line => {
                const row = document.createElement('div');
                row.className = 'grid grid-cols-12 gap-2 items-center text-sm';
                row.innerHTML = `
                    <span class="col-span-5 text-white truncate">${escapeHtml(line.name)}</span>
                    <span class="col-span-3 text-center font-mono text-xs text-gray-400">${line.sold} / ${line.remaining}</span>
                    <input type="number" min="0" max="${line.remaining}" value="0" step="1"
                           data-sku="${escapeHtml(line.sku)}"
                           class="refund-qty-input col-span-4 rounded-lg border border-gray-700 bg-gray-900 px-2 py-1 text-right font-mono text-sm text-white focus:border-amber-500 focus:outline-none">
                `;
                linesList.appendChild(row);
            });

            linesContainer.classList.remove('hidden');
            submitBtn.classList.remove('hidden');
        } catch (error) {
            lookupError.textContent = error.message;
            lookupError.classList.remove('hidden');
        }
    }

    lookupBtn.addEventListener('click', lookupOrder);
    orderIdInput.addEventListener('keydown', (e) => { if (e.key === 'Enter') lookupOrder(); });

    submitBtn.addEventListener('click', async () => {
        submitError.classList.add('hidden');
        const qtyInputs = [...linesList.querySelectorAll('.refund-qty-input')];
        const lines = qtyInputs
            .map(input => ({ sku: input.dataset.sku, qty: parseInt(input.value, 10) || 0 }))
            .filter(line => line.qty > 0);

        if (lines.length === 0) {
            submitError.textContent = 'Enter a refund quantity greater than 0 for at least one item.';
            submitError.classList.remove('hidden');
            return;
        }

        submitBtn.disabled = true;
        submitBtn.textContent = 'Processing…';
        try {
            const response = await fetch(`${API_BASE}/process_refund.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ order_id: currentOrderId, reason: reasonInput.value.trim(), lines }),
                credentials: 'same-origin',
            });
            const result = await response.json();
            if (!response.ok || !result.ok) throw new Error(result.error || 'Refund failed.');

            logMiddlewareMessage('POS', `Refund processed for order [${currentOrderId}]: ₱${Number(result.total_refunded).toFixed(2)} across ${result.lines.length} line(s).`);
            modal.classList.add('hidden');
            bootstrapSystemDatastream(); // re-fetch catalog so restocked quantities show immediately
        } catch (error) {
            submitError.textContent = error.message;
            submitError.classList.remove('hidden');
        } finally {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Process Refund';
        }
    });
}

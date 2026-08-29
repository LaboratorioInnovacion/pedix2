(() => {
  const post = (url, data, method = 'POST') => fetch(url, { method, headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) });
  const refresh = () => window.location.reload();
  document.querySelectorAll('[data-cart-qty]').forEach((btn) => btn.addEventListener('click', () => {
    const row = btn.closest('[data-cart-item-id]'); const input = row?.querySelector('input[name="qty"]');
    if (!row || !input) return; const next = Math.max(1, Number(input.value || 1) + (btn.dataset.cartQty === 'plus' ? 1 : -1)); input.value = String(next);
    post(`/api/cart/items/${row.dataset.cartItemId}`, { qty: next }, 'PATCH').then(refresh);
  }));
  document.querySelectorAll('[data-cart-remove]').forEach((btn) => btn.addEventListener('click', (event) => {
    const row = btn.closest('[data-cart-item-id]'); if (!row) return; event.preventDefault(); post(`/api/cart/items/${row.dataset.cartItemId}`, {}, 'DELETE').then(refresh);
  }));
  document.querySelectorAll('[data-cart-coupon]').forEach((form) => form.addEventListener('submit', (event) => {
    event.preventDefault(); const code = new FormData(form).get('coupon_code') || ''; post('/api/cart/coupon', { coupon_code: code }).then(refresh);
  }));
  const fulfillment = document.querySelector('[data-fulfillment]'); const address = document.querySelector('[data-address-fields]');
  const toggle = () => { if (address && fulfillment) address.hidden = fulfillment.value !== 'delivery'; };
  fulfillment?.addEventListener('change', toggle); toggle();
  const confirm = document.querySelector('[data-confirm-order]');
  const money = (cents) => `$${(Number(cents || 0) / 100).toFixed(2).replace('.', ',')}`;
  const renderBreakdown = (breakdown) => {
    const table = document.querySelector('[data-confirm-breakdown]'); if (!table || !breakdown) return;
    const promo = Number(breakdown.item_promotions_cents || 0) + Number(breakdown.order_promotions_cents || 0);
    table.innerHTML = `<tr><th>Concepto</th><th>Importe</th></tr><tr><td>Ítems</td><td>${money(breakdown.gross_items_cents)}</td></tr><tr><td>Promos</td><td>- ${money(promo)}</td></tr><tr><td>Cupón</td><td>- ${money(breakdown.coupon_discount_cents)}</td></tr><tr><td>Descuento por pago</td><td>- ${money(breakdown.payment_discount_cents)}</td></tr><tr><td>Envío</td><td>${money(breakdown.delivery_fee_cents)}</td></tr><tr><th>Total</th><th>${money(breakdown.grand_total_cents)}</th></tr>`;
  };
  confirm?.addEventListener('click', async () => {
    if (confirm.disabled) return; confirm.disabled = true;
    const keyName = 'vo.checkout.idempotency_key';
    let key = sessionStorage.getItem(keyName); if (!key) { key = (crypto?.randomUUID?.() || String(Date.now())); sessionStorage.setItem(keyName, key); }
    const accepted = JSON.parse(confirm.dataset.acceptedTotals || '{}');
    const customer = { name: document.querySelector('[data-customer-name]')?.value || '', phone: document.querySelector('[data-customer-phone]')?.value || '', email: document.querySelector('[data-customer-email]')?.value || '' };
    const res = await post(confirm.dataset.confirmUrl || '/api/cart/confirm', { idempotency_key: key, accepted_totals: accepted, customer });
    const json = await res.json();
    if (json.ok && json.order?.public_token) { sessionStorage.removeItem(keyName); window.location.href = `/pedido/${json.order.public_token}`; return; }
    confirm.disabled = false;
    const message = document.querySelector('[data-confirm-message]');
    if (message) { message.hidden = false; message.textContent = json.error === 'price_changed' ? 'Los precios cambiaron. Revisá el nuevo total antes de confirmar.' : (json.error?.message || 'No pudimos confirmar el pedido.'); }
    if (json.error === 'price_changed') renderBreakdown(json.breakdown);
  });
})();

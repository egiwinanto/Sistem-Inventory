(() => {
    document.querySelectorAll('[data-password-toggle]').forEach((button) => {
        button.addEventListener('click', () => {
            const input = button.closest('.password-field')?.querySelector('input');
            if (!input) return;
            const showing = input.type === 'text';
            input.type = showing ? 'password' : 'text';
            button.textContent = showing ? 'Lihat' : 'Sembunyi';
            button.setAttribute('aria-label', showing ? 'Tampilkan password' : 'Sembunyikan password');
        });
    });
    const shell = document.querySelector('[data-sidebar-shell]');
    document.querySelectorAll('[data-sidebar-toggle]').forEach((button) => {
        button.addEventListener('click', () => shell?.classList.toggle('sidebar-open'));
    });
    document.querySelector('[data-sidebar-overlay]')?.addEventListener('click', () => shell?.classList.remove('sidebar-open'));

    document.querySelectorAll('[data-modal-open]').forEach((button) => {
        button.addEventListener('click', () => {
            const modal = document.getElementById(button.dataset.modalOpen);
            modal?.classList.add('open');
            document.body.style.overflow = 'hidden';
        });
    });

    const closeModal = (modal) => {
        modal?.classList.remove('open');
        if (!document.querySelector('.modal-backdrop.open')) document.body.style.overflow = '';
    };

    document.querySelectorAll('[data-modal-close]').forEach((button) => {
        button.addEventListener('click', () => closeModal(button.closest('.modal-backdrop')));
    });
    document.querySelectorAll('.modal-backdrop').forEach((modal) => {
        modal.addEventListener('click', (event) => {
            if (event.target === modal) closeModal(modal);
        });
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') document.querySelectorAll('.modal-backdrop.open').forEach(closeModal);
    });

    document.querySelectorAll('[data-dismiss]').forEach((button) => {
        button.addEventListener('click', () => button.closest('.alert')?.remove());
    });
    setTimeout(() => document.querySelectorAll('[data-auto-dismiss]').forEach((el) => el.remove()), 5000);

    document.querySelectorAll('[data-confirm]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (!confirm(form.dataset.confirm || 'Yakin ingin melanjutkan?')) event.preventDefault();
        });
    });

    document.querySelectorAll('[data-live-search]').forEach((input) => {
        const target = document.querySelector(input.dataset.liveSearch);
        if (!target) return;
        input.addEventListener('input', () => {
            const query = input.value.toLowerCase().trim();
            target.querySelectorAll('[data-search-row]').forEach((row) => {
                row.hidden = !row.textContent.toLowerCase().includes(query);
            });
        });
    });

    const fillFormFromDataset = (button, form) => {
        Object.entries(button.dataset).forEach(([key, value]) => {
            if (!key.startsWith('field')) return;
            const fieldName = key.substring(5);
            const normalized = fieldName.charAt(0).toLowerCase() + fieldName.slice(1);
            const input = form.elements[normalized] || form.querySelector(`[name="${normalized}"]`);
            if (!input) return;
            if (input.type === 'checkbox') input.checked = value === '1' || value === 'true';
            else input.value = value;
        });
    };

    document.querySelectorAll('[data-edit-target]').forEach((button) => {
        button.addEventListener('click', () => {
            const modal = document.getElementById(button.dataset.editTarget);
            const form = modal?.querySelector('form');
            if (!modal || !form) return;
            form.reset();
            fillFormFromDataset(button, form);
            const title = modal.querySelector('[data-modal-title]');
            if (title) title.textContent = button.dataset.editTitle || 'Edit Data';
            modal.classList.add('open');
            document.body.style.overflow = 'hidden';
        });
    });

    document.querySelectorAll('[data-new-target]').forEach((button) => {
        button.addEventListener('click', () => {
            const modal = document.getElementById(button.dataset.newTarget);
            const form = modal?.querySelector('form');
            form?.reset();
            if (form?.elements.id) form.elements.id.value = '';
            const title = modal?.querySelector('[data-modal-title]');
            if (title) title.textContent = button.dataset.newTitle || 'Tambah Data';
            modal?.classList.add('open');
            document.body.style.overflow = 'hidden';
        });
    });

    function setupDynamicLines(containerSelector, addSelector, templateSelector) {
        const container = document.querySelector(containerSelector);
        const addButton = document.querySelector(addSelector);
        const template = document.querySelector(templateSelector);
        if (!container || !addButton || !template) return;

        const addLine = () => {
            const clone = template.content.cloneNode(true);
            container.appendChild(clone);
        };
        addButton.addEventListener('click', () => {
            addLine();
            document.querySelector('[data-transaction-type]')?.dispatchEvent(new Event('change'));
        });
        container.addEventListener('click', (event) => {
            const remove = event.target.closest('[data-remove-line]');
            if (!remove) return;
            const line = remove.closest('[data-line]');
            if (container.querySelectorAll('[data-line]').length > 1) line?.remove();
        });
        if (!container.children.length) addLine();
    }

    setupDynamicLines('[data-stock-lines]', '[data-add-stock-line]', '#stock-line-template');
    setupDynamicLines('[data-recipe-lines]', '[data-add-recipe-line]', '#recipe-line-template');

    const typeSelect = document.querySelector('[data-transaction-type]');
    const supplierGroup = document.querySelector('[data-supplier-group]');
    const updateStockForm = () => {
        if (!typeSelect) return;
        const incoming = ['IN', 'ADJUSTMENT_PLUS'].includes(typeSelect.value);
        if (supplierGroup) supplierGroup.hidden = typeSelect.value !== 'IN';
        document.querySelectorAll('[data-cost-field]').forEach((el) => el.hidden = !incoming);
    };
    typeSelect?.addEventListener('change', updateStockForm);
    updateStockForm();

    document.querySelectorAll('[data-fill-transaction]').forEach((button) => {
        button.addEventListener('click', () => {
            const form = document.querySelector('#stockTransactionModal form');
            if (!form) return;
            form.reset();
            const type = button.dataset.fillTransaction;
            if (form.elements.type) form.elements.type.value = type;
            typeSelect?.dispatchEvent(new Event('change'));
            document.getElementById('stockTransactionModal')?.classList.add('open');
            document.body.style.overflow = 'hidden';
        });
    });


    document.querySelectorAll('[data-edit-menu]').forEach((button) => {
        button.addEventListener('click', () => {
            const modal = document.getElementById('menuModal');
            const form = modal?.querySelector('form');
            const container = modal?.querySelector('[data-recipe-lines]');
            const template = document.querySelector('#recipe-line-template');
            if (!modal || !form || !container || !template) return;

            let data;
            try {
                data = JSON.parse(button.dataset.editMenu || '{}');
            } catch {
                return;
            }

            form.reset();
            form.elements.id.value = data.id ?? '';
            form.elements.code.value = data.code ?? '';
            form.elements.name.value = data.name ?? '';
            form.elements.selling_price.value = data.selling_price ?? 0;
            form.elements.is_active.checked = Boolean(Number(data.is_active));
            container.innerHTML = '';

            (data.recipes || []).forEach((recipe) => {
                const fragment = template.content.cloneNode(true);
                const line = fragment.querySelector('[data-line]');
                line.querySelector('[name="recipe_item_id[]"]').value = String(recipe.item_id);
                line.querySelector('[name="recipe_quantity[]"]').value = recipe.quantity;
                container.appendChild(fragment);
            });

            if (!container.children.length) {
                container.appendChild(template.content.cloneNode(true));
            }

            const title = modal.querySelector('[data-modal-title]');
            if (title) title.textContent = 'Edit Menu & Resep';
            modal.classList.add('open');
            document.body.style.overflow = 'hidden';
        });
    });

    document.querySelectorAll('[data-produce-menu]').forEach((button) => {
        button.addEventListener('click', () => {
            const modal = document.getElementById('produceMenuModal');
            if (!modal) return;
            modal.querySelector('[name="menu_id"]').value = button.dataset.menuId;
            modal.querySelector('[data-menu-name]').textContent = button.dataset.menuName;
            modal.classList.add('open');
            document.body.style.overflow = 'hidden';
        });
    });

    const posRoot = document.querySelector('[data-pos]');
    if (posRoot) {
        const cart = new Map();
        const currency = posRoot.dataset.currency || 'Rp';
        const lines = posRoot.querySelector('[data-pos-lines]');
        const empty = posRoot.querySelector('[data-pos-empty]');
        const count = posRoot.querySelector('[data-pos-count]');
        const subtotalEl = posRoot.querySelector('[data-pos-subtotal]');
        const discountEl = posRoot.querySelector('[data-pos-discount]');
        const discountText = posRoot.querySelector('[data-pos-discount-text]');
        const totalEl = posRoot.querySelector('[data-pos-total]');
        const paidEl = posRoot.querySelector('[data-pos-paid]');
        const paidGroup = posRoot.querySelector('[data-pos-paid-group]');
        const changeEl = posRoot.querySelector('[data-pos-change]');
        const paymentEl = posRoot.querySelector('[data-pos-payment]');
        const submitEl = posRoot.querySelector('[data-pos-submit]');
        let currentTotal = 0;

        const formatMoney = (value) => `${currency} ${Math.max(0, Number(value) || 0).toLocaleString('id-ID', { maximumFractionDigits: 0 })}`;

        const updateSummary = () => {
            const subtotal = [...cart.values()].reduce((sum, item) => sum + (item.price * item.quantity), 0);
            const discount = Math.min(subtotal, Math.max(0, Number(discountEl?.value) || 0));
            currentTotal = Math.max(0, subtotal - discount);
            const isCash = paymentEl?.value === 'CASH';
            const paid = isCash ? Math.max(0, Number(paidEl?.value) || 0) : currentTotal;
            const change = Math.max(0, paid - currentTotal);
            if (subtotalEl) subtotalEl.textContent = formatMoney(subtotal);
            if (discountText) discountText.textContent = `- ${formatMoney(discount)}`;
            if (totalEl) totalEl.textContent = formatMoney(currentTotal);
            if (changeEl) changeEl.textContent = formatMoney(change);
            if (count) count.textContent = String([...cart.values()].reduce((sum, item) => sum + item.quantity, 0));
            if (submitEl) submitEl.disabled = cart.size === 0 || (isCash && paid < currentTotal);
        };

        const createButton = (text, action, disabled = false) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'qty-button';
            button.dataset.posAction = action;
            button.textContent = text;
            button.disabled = disabled;
            return button;
        };

        const renderCart = () => {
            if (!lines || !empty) return;
            lines.innerHTML = '';
            cart.forEach((item) => {
                const row = document.createElement('div');
                row.className = 'pos-cart-line';
                row.dataset.posLine = String(item.id);

                const hiddenMenu = document.createElement('input');
                hiddenMenu.type = 'hidden';
                hiddenMenu.name = 'menu_id[]';
                hiddenMenu.value = String(item.id);
                const hiddenQty = document.createElement('input');
                hiddenQty.type = 'hidden';
                hiddenQty.name = 'quantity[]';
                hiddenQty.value = String(item.quantity);

                const info = document.createElement('div');
                info.className = 'pos-line-info';
                const name = document.createElement('strong');
                name.textContent = item.name;
                const price = document.createElement('span');
                price.textContent = formatMoney(item.price);
                info.append(name, price);

                const controls = document.createElement('div');
                controls.className = 'qty-control';
                controls.appendChild(createButton('−', 'minus'));
                const qty = document.createElement('strong');
                qty.textContent = String(item.quantity);
                controls.appendChild(qty);
                controls.appendChild(createButton('+', 'plus', item.quantity >= item.available));

                const total = document.createElement('div');
                total.className = 'pos-line-total';
                const amount = document.createElement('strong');
                amount.textContent = formatMoney(item.price * item.quantity);
                const remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'line-remove';
                remove.dataset.posAction = 'remove';
                remove.textContent = 'Hapus';
                total.append(amount, remove);

                row.append(hiddenMenu, hiddenQty, info, controls, total);
                lines.appendChild(row);
            });
            empty.hidden = cart.size > 0;
            updateSummary();
        };

        posRoot.querySelectorAll('[data-pos-menu]').forEach((button) => {
            button.addEventListener('click', () => {
                let menu;
                try { menu = JSON.parse(button.dataset.posMenu || '{}'); } catch { return; }
                const current = cart.get(menu.id);
                if (current) {
                    if (current.quantity >= current.available) return;
                    current.quantity += 1;
                } else {
                    cart.set(menu.id, { ...menu, quantity: 1 });
                }
                renderCart();
            });
        });

        lines?.addEventListener('click', (event) => {
            const button = event.target.closest('[data-pos-action]');
            const row = event.target.closest('[data-pos-line]');
            if (!button || !row) return;
            const id = Number(row.dataset.posLine);
            const item = cart.get(id);
            if (!item) return;
            if (button.dataset.posAction === 'plus' && item.quantity < item.available) item.quantity += 1;
            if (button.dataset.posAction === 'minus') item.quantity -= 1;
            if (button.dataset.posAction === 'remove' || item.quantity <= 0) cart.delete(id);
            renderCart();
        });

        posRoot.querySelector('[data-pos-search]')?.addEventListener('input', (event) => {
            const query = event.target.value.toLowerCase().trim();
            posRoot.querySelectorAll('[data-pos-menu]').forEach((button) => {
                button.hidden = !button.textContent.toLowerCase().includes(query);
            });
        });

        posRoot.querySelector('[data-pos-clear]')?.addEventListener('click', () => {
            cart.clear();
            renderCart();
        });
        discountEl?.addEventListener('input', updateSummary);
        paidEl?.addEventListener('input', updateSummary);
        paymentEl?.addEventListener('change', () => {
            const isCash = paymentEl.value === 'CASH';
            if (paidGroup) paidGroup.hidden = !isCash;
            if (paidEl) {
                paidEl.disabled = !isCash;
                if (!isCash) paidEl.value = String(currentTotal);
            }
            updateSummary();
        });
        posRoot.querySelector('[data-pos-form]')?.addEventListener('submit', (event) => {
            if (cart.size === 0) {
                event.preventDefault();
                alert('Keranjang kasir masih kosong.');
                return;
            }
            if (paymentEl?.value === 'CASH' && (Number(paidEl?.value) || 0) < currentTotal) {
                event.preventDefault();
                alert('Uang yang diterima masih kurang.');
            }
        });
        paymentEl?.dispatchEvent(new Event('change'));
        renderCart();
    }

    document.querySelectorAll('[data-print-receipt]').forEach((button) => {
        button.addEventListener('click', () => {
            document.body.classList.add('receipt-print-mode');
            window.print();
            setTimeout(() => document.body.classList.remove('receipt-print-mode'), 300);
        });
    });

})();


// Konfirmasi logout agar pengguna tidak keluar karena salah tekan.
document.querySelectorAll('[data-confirm-logout]').forEach((link) => {
    link.addEventListener('click', (event) => {
        if (!window.confirm('Keluar dari aplikasi StockBite?')) {
            event.preventDefault();
        }
    });
});

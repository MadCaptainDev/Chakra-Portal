<script>
    function lineItemsWithVariables({ previewUrl, clientInputId, monthInputId }) {
        return {
            focusedQtyIndex: null,
            previewCounts: null,
            previewTimer: null,
            variableTokens: @js(collect(\App\Support\InvoiceQuantityVariable::TOKENS)->values()->all()),

            initLineItemsVariables() {
                this.schedulePreview();
                document.getElementById(clientInputId)?.addEventListener('change', () => this.schedulePreview());
                document.getElementById(monthInputId)?.addEventListener('change', () => this.schedulePreview());
            },

            focusQty(index) {
                this.focusedQtyIndex = index;
            },

            insertVariable(token) {
                if (this.focusedQtyIndex === null) {
                    if (this.items.length === 0) {
                        return;
                    }
                    this.focusedQtyIndex = this.items.length - 1;
                }

                this.items[this.focusedQtyIndex].quantity = token;
                this.schedulePreview();
            },

            isVariableQuantity(value) {
                if (typeof value !== 'string') {
                    return false;
                }

                return this.variableTokens.includes(value.trim().toLowerCase());
            },

            resolvedQuantity(item) {
                if (this.isVariableQuantity(item.quantity)) {
                    const key = item.quantity.trim().toLowerCase().slice(2, -2);

                    return this.previewCounts?.[key] ?? null;
                }

                const numeric = Number(item.quantity);

                return Number.isFinite(numeric) ? numeric : 0;
            },

            lineTotal(item) {
                const qty = this.resolvedQuantity(item);

                return (qty ?? 0) * (Number(item.unit_price) || 0);
            },

            hasClientAndMonth() {
                return Boolean(document.getElementById(clientInputId)?.value)
                    && Boolean(document.getElementById(monthInputId)?.value);
            },

            schedulePreview() {
                clearTimeout(this.previewTimer);
                this.previewTimer = setTimeout(() => this.fetchPreview(), 250);
            },

            async fetchPreview() {
                const clientId = document.getElementById(clientInputId)?.value;
                const month = document.getElementById(monthInputId)?.value;

                if (! clientId || ! month) {
                    this.previewCounts = null;

                    return;
                }

                try {
                    const url = new URL(previewUrl, window.location.origin);
                    url.searchParams.set('client_id', clientId);
                    url.searchParams.set('month', month.slice(0, 7) + '-01');

                    const response = await fetch(url, { headers: { Accept: 'application/json' } });

                    if (response.ok) {
                        this.previewCounts = await response.json();
                    }
                } catch (error) {
                    this.previewCounts = null;
                }
            },
        };
    }
</script>

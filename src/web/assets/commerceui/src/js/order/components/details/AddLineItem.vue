<template>
    <div>
        <template v-if="!showForm">
            <template v-if="lineItems.length > 0">
                <div class="text-left">
                    <btn-link
                        @click="showForm = true"
                        button-class="btn icon add"
                        >{{ 'Add a line item' | t('commerce') }}</btn-link
                    >
                </div>
            </template>
            <template v-else>
                <div class="starter">
                    <div data-icon="info"></div>
                    <h2>{{ 'Your order is empty' | t('commerce') }}</h2>
                    <btn-link
                        @click="showForm = true"
                        button-class="btn icon add"
                        >{{ 'Add a line item' | t('commerce') }}</btn-link
                    >
                </div>
            </template>
        </template>
        <template v-else>
            <div>
                <div class="flex add-line-item-table-header pb">
                    <h2>
                        {{ $options.filters.t('Add a line item', 'commerce') }}
                    </h2>
                    <form
                        @submit.prevent="lineItemAdd()"
                        class="add-line-item-form"
                    >
                        <div class="buttons buttons--add-line-item">
                            <input
                                type="button"
                                class="btn"
                                :class="{disabled: formDisabled}"
                                :disabled="formDisabled"
                                :value="
                                    $options.filters.t('Cancel', 'commerce')
                                "
                                @click="showForm = false"
                            />
                            <input
                                type="submit"
                                class="btn secondary"
                                :class="{disabled: submitDisabled}"
                                :disabled="submitDisabled"
                                :value="$options.filters.t('Add', 'commerce')"
                                @click.prevent="lineItemAdd()"
                            />
                        </div>
                    </form>
                </div>
                <admin-table
                    :allow-multiple-selections="true"
                    table-data-endpoint="commerce/orders/purchasables-table"
                    :checkboxes="true"
                    :checkbox-status="isCheckboxEnabled"
                    :columns="purchasableTableColumns"
                    :padded="true"
                    per-page="10"
                    search="true"
                    @onSelect="handleCheckboxSelect"
                    @data="handleTableData"
                ></admin-table>

                <form
                    @submit.prevent="lineItemAdd()"
                    class="add-line-item-form"
                >
                    <div class="buttons buttons--add-line-item">
                        <input
                            type="button"
                            class="btn"
                            :class="{disabled: formDisabled}"
                            :disabled="formDisabled"
                            :value="$options.filters.t('Cancel', 'commerce')"
                            @click="showForm = false"
                        />
                        <input
                            type="submit"
                            class="btn secondary"
                            :class="{disabled: submitDisabled}"
                            :disabled="submitDisabled"
                            :value="$options.filters.t('Add', 'commerce')"
                            @click.prevent="lineItemAdd()"
                        />
                    </div>
                </form>
            </div>
        </template>
    </div>
</template>

<script>
    import {mapActions, mapGetters, mapState} from 'vuex';
    import _find from 'lodash.find';
    import AdminTable from '@craftcms/vue/admintable/App';

    export default {
        components: {
            AdminTable,
        },

        data() {
            return {
                showForm: false,
                selectedPurchasables: [],
                currentTableData: null,
                purchasableTableColumns: [
                    {
                        name: 'description',
                        title: this.$options.filters.t(
                            'Description',
                            'commerce'
                        ),
                        sortField: 'description',
                    },
                    {
                        name: 'sku',
                        title: this.$options.filters.t('SKU', 'commerce'),
                        sortField: 'sku',
                    },
                    {
                        name: 'priceAsCurrency',
                        title: this.$options.filters.t('Price', 'commerce'),
                        sortField: 'price',
                    },
                    {
                        name: 'isAvailable',
                        title: this.$options.filters.t(
                            'Available?',
                            'commerce'
                        ),
                        callback: function (value) {
                            if (value) {
                                return '<span data-icon="check" title=""></span>';
                            }
                        },
                    },
                    {
                        name: '__slot:detail',
                        title: '',
                        titleClass: 'thin',
                        callback: function (value) {
                            if (
                                value &&
                                (Object.keys(value).length || value.length)
                            ) {
                                return '<textarea>' + value + '</textarea>';
                            }
                        },
                    },
                ],
            };
        },

        computed: {
            ...mapState({
                purchasables: (state) => state.purchasables,
            }),

            ...mapGetters(['getErrors', 'canAddLineItem', 'orderId']),

            formDisabled() {
                return !this.canAddLineItem;
            },

            submitDisabled() {
                if (!this.canAddLineItem || !this.selectedPurchasables.length) {
                    return true;
                }

                return false;
            },

            lineItems() {
                return this.$store.state.draft.order.lineItems;
            },
        },

        methods: {
            ...mapActions(['displayError']),

            lineItemAdd() {
                if (!this.canAddLineItem) {
                    this.displayError(
                        this.$options.filters.t(
                            'You are not allowed to add a line item.',
                            'commerce'
                        )
                    );
                    return;
                }

                this.addLineItem();
            },

            addLineItem() {
                if (this.selectedPurchasables.length) {
                    let lineItems = [];
                    for (let i = 0; i < this.selectedPurchasables.length; i++) {
                        let purchasable = this.selectedPurchasables[i];
                        if (purchasable && purchasable.isAvailable) {
                            lineItems.push({
                                id: null,
                                lineItemStatusId: null,
                                salePrice: purchasable.price,
                                qty: '1',
                                note: '',
                                privateNote: '',
                                orderId: this.orderId,
                                purchasableId: purchasable.id,
                                sku: purchasable.sku,
                                options: [],
                                adjustments: [],
                                isNew: true,
                                uid: purchasable.newLineItemUid,
                            });
                            this.$store.commit(
                                'updateRecentlyAddedLineItems',
                                purchasable.id +
                                    '-' +
                                    purchasable.newLineItemOptionsSignature
                            );
                        }
                    }

                    if (lineItems.length) {
                        this.$emit('addLineItem', lineItems);
                    }
                }

                this.selectedPurchasables = [];
                this.showForm = false;
            },

            isCheckboxEnabled(row) {
                return row.isAvailable;
            },

            handleCheckboxSelect(ids) {
                if (ids && ids.length) {
                    let $this = this;
                    this.selectedPurchasables = ids.map((id) => {
                        return _find($this.currentTableData, {id: id});
                    });
                } else {
                    this.selectedPurchasables = [];
                }
            },

            handleTableData(data) {
                this.currentTableData = data;
            },
        },
    };
</script>

<style lang="scss">
    @import 'craftcms-sass/mixins';

    /* Starter */

    .starter {
        text-align: center;

        div[data-icon] {
            font-size: 4em;
            color: #f1f5f8;
        }
    }

    /* Add line item form */

    .add-line-item-form {
        max-width: 100%;
    }

    .add-line-item-table-header {
        justify-content: space-between;
        align-items: center;

        h2 {
            margin: 0;
        }
    }

    /* Purchasable select option */

    .purchasable-select-option {
        display: flex;

        .description {
            flex-grow: 1;
        }

        .sku {
            color: $lightTextColor;
            @include margin-right(20px);
        }

        &.notAvailable {
            .description {
                color: $lightTextColor;
            }
            .price {
                color: red;
            }
        }

        .price {
            width: 10%;
            text-align: right;
        }
    }

    .buttons--add-line-item {
        justify-content: flex-end;
    }
</style>

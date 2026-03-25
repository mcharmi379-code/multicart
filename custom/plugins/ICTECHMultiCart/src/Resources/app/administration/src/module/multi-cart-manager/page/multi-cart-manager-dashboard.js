import template from './multi-cart-manager-dashboard.html.twig';
import './multi-cart-manager-dashboard.scss';

const { Application, Component, Mixin } = Shopware;
const { Criteria } = Shopware.Data;

Component.register('multi-cart-manager-dashboard', {
    template,

    mixins: [
        Mixin.getByName('notification'),
    ],

    data() {
        return {
            isLoading: false,
            activeCarts: [],
            analytics: {},
            completedOrders: [],
            selectedSalesChannel: null,
            salesChannels: [],
        };
    },

    computed: {
        httpClient() {
            return Application.getContainer('init').httpClient;
        },

        activeCartsColumns() {
            return [
                { property: 'name', dataIndex: 'name', label: this.$tc('multi-cart-manager.dashboard.cartName'), primary: true },
                { property: 'owner', dataIndex: 'owner', label: this.$tc('multi-cart-manager.dashboard.owner') },
                { property: 'itemCount', dataIndex: 'itemCount', label: this.$tc('multi-cart-manager.dashboard.itemCount') },
                { property: 'total', dataIndex: 'total', label: this.$tc('multi-cart-manager.dashboard.total') },
                { property: 'lastActivity', dataIndex: 'lastActivity', label: this.$tc('multi-cart-manager.dashboard.lastActivity') },
                { property: 'createdAt', dataIndex: 'createdAt', label: this.$tc('multi-cart-manager.dashboard.createdAt') },
            ];
        },

        completedOrdersColumns() {
            return [
                { property: 'cartName', dataIndex: 'cartName', label: this.$tc('multi-cart-manager.dashboard.cartName'), primary: true },
                { property: 'orderId', dataIndex: 'orderId', label: this.$tc('multi-cart-manager.dashboard.orderId') },
                { property: 'promotionCode', dataIndex: 'promotionCode', label: this.$tc('multi-cart-manager.dashboard.promotionCode') },
                { property: 'discount', dataIndex: 'discount', label: this.$tc('multi-cart-manager.dashboard.discount') },
                { property: 'orderedAt', dataIndex: 'orderedAt', label: this.$tc('multi-cart-manager.dashboard.orderedAt') },
            ];
        },
    },

    created() {
        this.loadSalesChannels();
    },

    methods: {
        loadSalesChannels() {
            this.isLoading = true;
            this.httpClient.get(
                '/_action/multi-cart/sales-channels',
                { headers: Shopware.Context.api.apiResourceHeaders }
            ).then((response) => {
                this.salesChannels = response.data;
                if (this.salesChannels.length > 0) {
                    this.selectedSalesChannel = this.salesChannels[0].id;
                    this.loadDashboard();
                }
                this.isLoading = false;
            }).catch((error) => {
                console.error('Failed to load sales channels:', error);
                this.createNotificationError({
                    title: this.$tc('multi-cart-manager.notification.error'),
                    message: this.$tc('multi-cart-manager.notification.loadSalesChannelsError'),
                });
                this.isLoading = false;
            });
        },

        loadDashboard() {
            this.isLoading = true;
            this.httpClient.get(
                `/_action/multi-cart/dashboard?salesChannelId=${this.selectedSalesChannel}`,
                { headers: Shopware.Context.api.apiResourceHeaders }
            ).then((response) => {
                this.activeCarts = response.data.activeCarts;
                this.analytics = response.data.analytics;
                this.completedOrders = response.data.completedOrders;
                this.isLoading = false;
            }).catch(() => {
                this.createNotificationError({
                    title: this.$tc('multi-cart-manager.notification.error'),
                    message: this.$tc('multi-cart-manager.notification.loadDashboardError'),
                });
                this.isLoading = false;
            });
        },

        onSalesChannelChange() {
            this.loadDashboard();
        },
    },
});

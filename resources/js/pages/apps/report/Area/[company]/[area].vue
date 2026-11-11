<script setup>
import { Spanish } from "flatpickr/dist/l10n/es.js";
import TotalOverview from "@/views/apps/report/Area/totalOverview.vue";

// Router + route
const route = useRoute();
const router = useRouter();

// 📦 Parámetros iniciales
const nameArea = ref(route.params.area);
const company = route.params.company;
const id = ref();

// 📅 Filtros
const dateRange = ref("");
const typeSel = ref();
const searchQuery = ref("");
const isExporting = ref(false);

// 🔁 Recuperar estado si viene desde navegación anterior
const state = router.options.history.state;
if (state) {
  dateRange.value = state.dateRange ?? "";
  typeSel.value = state.type ?? "ALL";
  id.value = state.id ?? null;
}

// ⚙️ Control de tabla
const page = ref(1);
const sortBy = ref();
const orderBy = ref();

const headers = [
  { title: "Proveedor", key: "data-table-group", sortable: false },
  { title: "Producto", key: "product_name", sortable: false },
  { title: "Unidad", key: "unidad", sortable: false },
  { title: "Cantidad", key: "cantidad", sortable: false },
  { title: "Precio Unitario (S/)", key: "precio_unitario", sortable: false },
  { title: "Precio con IGV (S/)", key: "precio_igv", sortable: false },
  { title: "Total (S/)", key: "total", sortable: false },
];

const groupBy = [{ key: "proveedor_name" }];

// 📡 Datos principales
const { data: suppliersData, execute: fetchSuppliers } = await useApi(
  createUrl("/reports/products", {
    query: {
      q: searchQuery,
      company,
      date: dateRange,
      type: typeSel,
      area: id,
    },
  })
);

// 🧠 Datos derivados
const suppliers = computed(() => suppliersData.value?.suppliers ?? []);
const total = computed(() => suppliersData.value?.total ?? 0);
const orderCounts = computed(() => suppliersData.value?.order_counts ?? {});
const ocOrders = computed(() => Number(orderCounts.value.oc ?? 0));
const osOrders = computed(() => Number(orderCounts.value.os ?? 0));

const tSuppliers = computed(() => {
  const data = suppliers.value;
  return new Set(data.map((i) => i.proveedor_id)).size;
});

const tMoney = computed(() =>
  Number(suppliersData.value?.money_total ?? suppliers.value.reduce((sum, i) => sum + (i.total || 0), 0))
);

// 📑 Control tabla
const updateOptions = (options) => {
  page.value = options.page;
  sortBy.value = options.sortBy?.[0]?.key;
  orderBy.value = options.sortBy?.[0]?.order;
};

// 🔗 Navegación a proveedor
const goToSupplier = (item) => {
  const idSupplier = item.items?.[0]?.raw?.proveedor_id;
  if (!idSupplier) return;

  router.push({
    name: "apps-supplier-id",
    params: { id: idSupplier },
    state: {
      company,
      dateRange: dateRange.value,
      type: typeSel.value,
    },
  });
};

// 🔗 Navegación a producto
const goToProduct = (item) => {
  if (!item.product_id) return;
  router.push({
    name: "apps-product-id",
    params: { id: item.product_id },
    state: {
      company,
      dateRange: dateRange.value,
      type: typeSel.value,
    },
  });
};

const getSupplierTotal = (items) => {
  const sum = items?.[0]?.raw?.supplier_total
    ?? items.reduce((acc, i) => acc + (i.raw.total || 0), 0);

  return sum.toLocaleString("es-PE", {
    style: "currency",
    currency: "PEN",
  });
};

const exportAreaOrders = async () => {
  isExporting.value = true;

  try {
    const response = await $api.raw('/reports/products/export', {
      method: 'GET',
      query: {
        company,
        area: id.value,
        date: dateRange.value,
        type: typeSel.value,
      },
      responseType: 'blob',
    });

    const url = window.URL.createObjectURL(response._data);
    const link = document.createElement('a');
    link.href = url;
    link.download = `reporte-area-${company}-${id.value}.xlsx`;
    document.body.appendChild(link);
    link.click();
    link.remove();
    window.URL.revokeObjectURL(url);
  } finally {
    isExporting.value = false;
  }
};
</script>

<template>
  <VRow class="match-height">
    <!-- 📊 Filtros -->
    <VCol cols="12" md="4">
      <VCard class="mb-6">
        <VCardItem class="pb-4">
          <VCardTitle>Filtros</VCardTitle>
        </VCardItem>
        <VCardText>
          <VRow>
            <VCol cols="12">
              <AppDateTimePicker
                v-model="dateRange"
                label="Rango de fechas"
                placeholder="Selecciona el rango"
                :config="{
                  mode: 'range',
                  dateFormat: 'Y-m-d',
                  locale: Spanish,
                  maxDate: new Date(),
                }"
              />
            </VCol>

            <VCol cols="12">
              <VSelect
                v-model="typeSel"
                label="Selecciona Tipo Orden"
                placeholder="Selecciona Tipo Orden"
                :items="[
                  { title: 'Orden de Compra', value: 'OC' },
                  { title: 'Orden de Servicio', value: 'OS' },
                  { title: 'Todos', value: 'ALL' },
                ]"
                item-title="title"
                item-value="value"
                no-data-text="No hay tipos disponibles"
              />
            </VCol>
          </VRow>
        </VCardText>
      </VCard>
    </VCol>

    <!-- 🧾 Resumen -->
    <VCol cols="12" md="8">
      <TotalOverview
        :name-area="nameArea"
        :money="tMoney"
        :suppliers="tSuppliers"
        :products="total"
        :oc-orders="ocOrders"
        :os-orders="osOrders"
      />
    </VCol>
  </VRow>

  <!-- 📋 Tabla -->
  <VCard class="mt-6">
    <VCardItem>
      <div class="d-flex justify-space-between align-center flex-wrap gap-4">
        <VCardTitle>Órdenes de compra y servicio</VCardTitle>
        <div class="d-flex align-center gap-3">
          <VBtn
            color="success"
            prepend-icon="ri-file-excel-2-line"
            :loading="isExporting"
            :disabled="isExporting"
            @click="exportAreaOrders"
          >
            Exportar detalle
          </VBtn>
          <VTextField
            v-model="searchQuery"
            placeholder="Buscar Proveedor o Producto"
            density="compact"
            style="max-inline-size: 250px;"
          />
        </div>
      </div>
    </VCardItem>

    <VDataTableServer
      :headers="headers"
      :group-by="groupBy"
      :items="suppliers"
      :items-length="total"
      class="text-no-wrap"
      @update:options="updateOptions"
      hide-default-footer
    >
      <!-- 🏷 Producto clickeable -->
      <template #item.product_name="{ item }">
        <span class="cursor-pointer text-primary" @click="goToProduct(item)">
          {{ item.product_name }}
        </span>
      </template>

      <!-- 💰 Formato monetario -->
      <template #item.precio_unitario="{ item }">
        {{
          Number(item.precio_unitario).toLocaleString("es-PE", {
            style: "currency",
            currency: "PEN",
          })
        }}
      </template>

      <template #item.precio_igv="{ item }">
        {{
          Number(item.precio_igv).toLocaleString("es-PE", {
            style: "currency",
            currency: "PEN",
          })
        }}
      </template>

      <template #item.total="{ item }">
        {{
          Number(item.total).toLocaleString("es-PE", {
            style: "currency",
            currency: "PEN",
          })
        }}
      </template>

      <!-- 👥 Grupo de proveedores -->
      <template #data-table-group="{ props, item, count }">
        <td class="cursor-pointer">
          <VBtn v-bind="props" variant="text" density="comfortable" />

          <!-- Nombre del proveedor -->
          <span @click="goToSupplier(item)">
            {{ item.value }} ({{ count }})
          </span>

          <!-- 💰 Total por proveedor -->
          <span class="ms-3 text-primary fw-bold">
            {{ getSupplierTotal(item.items) }}
          </span>
        </td>
      </template>
    </VDataTableServer>
  </VCard>
</template>

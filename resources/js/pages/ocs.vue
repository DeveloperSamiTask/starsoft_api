<script setup>
import OCDetailDialog from "@/components/dialogs/OCDetailDialog.vue"
import AreaFilter from "@/components/filters/AreaFilter.vue"
import { resolveCompanySelection, syncSelectedCompany } from "@/composables/useCompanySelection"
import { useOrderFilters } from "@/composables/useOrderFilters"
import { Spanish } from "flatpickr/dist/l10n/es"

// 🎯 Usar composable de filtros
const { isJefeDeArea, canApprove, canMarkAsRead, availableStatuses, normalizeStatus, getStatusColor } = useOrderFilters()
const userData = useCookie('userData')

//Companies
const selectedCompany = ref(resolveCompanySelection({ userData: userData.value }))
const companies = ref([])
const isLoading = ref(true)
const errorMessage = ref(null) 

onMounted(async () => {
  try {
    const res = await $api('/users/companies', {
      method: 'GET',
      onResponseError({ response }) {
        throw new Error(response._data?.message || 'Error al obtener empresas')
      },
    })

    if (!Array.isArray(res)) {
      throw new Error('Formato de datos inválido')
    }

    companies.value = res.map(company => ({
      title: company.name,
      value: company.id,
      rawData: company,
    }))

    syncSelectedCompany(selectedCompany, userData.value, companies.value, company => company.value)
  } catch (error) {
    console.error('Error cargando empresas:', error)
    errorMessage.value = 'No se pudieron cargar las empresas. Intente nuevamente.'
    companies.value = []
  } finally {
    isLoading.value = false
  }
})

// End Companies

// Date Range Picker

const today = new Date()

// Un mes atrás; se recalcula también al cambiar de empresa.
const getDefaultDateRange = () => {
  const oneMonthAgo = new Date(today)

  oneMonthAgo.setMonth(oneMonthAgo.getMonth() - 1)

  return `${formatDate(oneMonthAgo)} a ${formatDate(today)}`
}

// Formatear en 'YYYY-MM-DD'
function formatDate(date) {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, "0")
  const day = String(date.getDate()).padStart(2, "0")
  
  return `${year}-${month}-${day}`
}

// Rango inicial: desde hoy hasta un mes atrás.
const dateRange = ref(getDefaultDateRange())

// End Date Range Picker

// 🏷️ Estados disponibles según rol
const status = availableStatuses.value

// Data table options
const itemsPerPage = ref(30)
const page = ref(1)
// Valores iniciales válidos para que la tabla no calcule dimensiones con undefined.
const sortBy = ref('')
const orderBy = ref('asc')
const isDialogVisible = ref(false)
const selectedItem = ref(null)
const selectedRows = ref([])

// 🎯 Filtro de área para JEFE DE AREA
const selectedArea = ref(null)
const areaPermissions = computed(() => useCookie('userData').value?.area_permissions ?? {})

// Headers  
const headers = [
  {
    title: "Empresa",
    key: "company",
  },
  {
    title: "Modulo",
    key: "module",
  },
  {
    title: "Tipo",
    key: "type",
  },
  {
    title: "Codigo",
    key: "code",
  },
  {
    title: "Asunto",
    key: "issue",
  },
  {
    title: "Fecha Envio",
    key: "issue_date",
    sortable: true,
  },
  {
    title: "Estado",
    key: "status",
  },
]

const searchQuery = ref("")
// Sin estado seleccionado: la API devuelve todos los estados.
const selectedStatus = ref(null)
const selectedType = ref()
const selectedSwitch = ref(false)
const isExporting = ref(false)
const isExportingWithDetails = ref(false)

watch(selectedCompany, (newCompany, previousCompany) => {
  if (newCompany === previousCompany) {
    return
  }

  // Al cambiar de empresa se limpian los filtros dependientes.
  selectedStatus.value = null
  selectedArea.value = null
  dateRange.value = getDefaultDateRange()
})


const { data: ocsData, execute: fetchOcs } = await useApi(
  createUrl("purchase-orders", {
    query: {
      q: searchQuery,
      company: selectedCompany,
      status: selectedStatus,
      area: selectedArea,
      date: dateRange,
      ignoreDateFilter: selectedSwitch,
      itemsPerPage,
      page,
      sortBy,
      orderBy,
    },
  }),
)

const updateOptions = options => {
  page.value = Number(options.page) || 1

  const activeSort = Array.isArray(options.sortBy) ? options.sortBy[0] : null

  sortBy.value = activeSort?.key || ''
  orderBy.value = activeSort?.order === 'desc' ? 'desc' : 'asc'
}

const exportOrders = async () => {
  isExporting.value = true

  try {
    // Descarga todas las filas que coinciden con los filtros actuales.
    const response = await $api.raw('/purchase-orders/export', {
      method: 'GET',
      query: {
        q: searchQuery.value,
        company: selectedCompany.value,
        status: selectedStatus.value,
        area: selectedArea.value,
        date: dateRange.value,
        ignoreDateFilter: selectedSwitch.value,
        sortBy: sortBy.value,
        orderBy: orderBy.value,
      },
      responseType: 'blob',
    })

    const blob = response._data
    const url = window.URL.createObjectURL(blob)
    const link = document.createElement('a')

    link.href = url
    link.download = 'ordenes-starcheck.xlsx'
    document.body.appendChild(link)
    link.click()
    link.remove()
    window.URL.revokeObjectURL(url)
  } catch (error) {
    console.error('Error exportando órdenes:', error)
  } finally {
    isExporting.value = false
  }
}

const exportOrdersWithDetails = async () => {
  isExportingWithDetails.value = true

  try {
    // Usa los mismos filtros de la tabla para generar el reporte por bloques de OC/OS.
    const response = await $api.raw('/purchase-orders/export-with-details', {
      method: 'GET',
      query: {
        q: searchQuery.value,
        company: selectedCompany.value,
        status: selectedStatus.value,
        area: selectedArea.value,
        date: dateRange.value,
        ignoreDateFilter: selectedSwitch.value,
        sortBy: sortBy.value,
        orderBy: orderBy.value,
      },
      responseType: 'blob',
    })

    const blob = response._data
    const url = window.URL.createObjectURL(blob)
    const link = document.createElement('a')

    link.href = url
    link.download = 'ordenes-starcheck-con-detalle.xlsx'
    document.body.appendChild(link)
    link.click()
    link.remove()
    window.URL.revokeObjectURL(url)
  } catch (error) {
    console.error('Error exportando órdenes con detalle:', error)
  } finally {
    isExportingWithDetails.value = false
  }
}

// Evita que una respuesta 500 de la API rompa el renderizado de la pantalla.
const ocs = computed(() => ocsData.value?.ocs ?? [])
const total = computed(() => ocsData.value?.total ?? 0)

const handleRowClick = item => {
  selectedItem.value = item
  isDialogVisible.value = true
  markAsRead(item)
}

const markAsRead = async item => {
  if (item.read || !canMarkAsRead.value) {
    return
  }

  try {
    await $api('/mark-as-read', {
      method: 'POST',
      body: {
        code: item.code,
        type: item.type,
        company: item.company,
      },
    })

    item.read = true
    await fetchOcs()
  } catch (error) {
    console.error('Error al marcar como leído', error)
  }
}

const isSnackbarVisible = ref(false)
const colorSnackbar = ref('primary')
const messageSnackbar = ref('')

const showSnackbar = ({ message, color = 'success' }) => {
  messageSnackbar.value = message
  colorSnackbar.value = color
  isSnackbarVisible.value = true
}
</script>

<template>
  <VCard class="mb-6">
    <VCardItem class="pb-4">
      <VCardItem> Filtros </VCardItem>
    </VCardItem>
    <VCardText>
      <VRow>
        <VCol
          cols="12"
          sm="4"
        >
          <VSelect
            v-model="selectedCompany"
            label="Selecciona empresa"
            placeholder="Selecciona empresa"
            :items="companies"
            item-title="title"
            item-value="value"
            :loading="isLoading"
            :error-messages="errorMessage"
            clearable
            clear-icon="ri-close-line"
            no-data-text="No hay empresas disponibles"
          />
        </VCol>
        <VCol
          cols="12"
          sm="3"
        >
          <VSelect
            v-model="selectedStatus"
            label="Seleccionar Estado"
            placeholder="Seleccionar Estado"
            :items="status"
            clearable
            clear-icon="ri-close-line"
          />
        </VCol>
        <!-- 🎯 Filtro de área - solo para JEFE DE AREA -->
        <AreaFilter
          v-if="isJefeDeArea"
          v-model="selectedArea"
          :selected-company="selectedCompany"
          :area-permissions="areaPermissions"
        />
        <VCol
          cols="12"
          sm="3"
        >
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
        <VCol
          cols="12"
          sm="2"
          class="d-flex  justify-center align-center"
        >
          <VSwitch
            v-model="selectedSwitch"
            label="Mostrar Todos"
            inset
            style="transform: scale(1.1);"
          />
        </VCol>
      </VRow>
    </VCardText>

    <VDivider />

    <VCardText class="d-flex flex-wrap gap-4 align-center">
      <VBtn
        color="success"
        prepend-icon="ri-file-excel-2-line"
        :loading="isExporting"
        :disabled="isExporting"
        @click="exportOrders"
      >
        Exportar Excel
      </VBtn>
      <VBtn
        color="primary"
        prepend-icon="ri-file-list-3-line"
        :loading="isExportingWithDetails"
        :disabled="isExporting || isExportingWithDetails"
        @click="exportOrdersWithDetails"
      >
        Excel con detalle
      </VBtn>
      <VSpacer />
      <div class="d-flex align-center gap-4 flex-wrap">
        <div class="app-user-search-filter">
          <VTextField
            v-model="searchQuery"
            placeholder="Buscar OC"
            density="compact"
          />
        </div>
      </div>
    </VCardText>

    <!-- SECTION datatable -->
    <VDataTableServer
      v-model:model-value="selectedRows"
      v-model:items-per-page="itemsPerPage"
      v-model:page="page"
      :headers="headers"
      show-select
      :items="ocs"
      :items-length="total"
      class="text-no-wrap rounded-0"
      hover
      @update:options="updateOptions"
    >
      <template #item.company="{ item }">
        <div
          class="d-flex align-center gap-x-3"
          :class="{ 'row-unread': item.read === false }"
          style="inline-size: 300px; white-space: normal; word-wrap: break-word;"
          @click="handleRowClick(item)"
        >
          <div class="d-flex flex-column">
            <span class="text-base">{{ item.company_name }}</span>
          </div>
        </div>
      </template>

      <template #item.module="{ item }">
        <div
          class="d-flex align-center gap-x-3"
          :class="{ 'row-unread': item.read === false }"
          style="cursor: pointer;"
          @click="handleRowClick(item)"
        >
          <div class="d-flex flex-column">
            <span class="text-base">{{ item.module }}</span>
          </div>
        </div>
      </template>

      <template #item.type="{ item }">
        <div
          class="d-flex align-center gap-x-3"
          :class="{ 'row-unread': item.read === false }"
          style="cursor: pointer;"
          @click="handleRowClick(item)"
        >
          <div class="d-flex flex-column">
            <span class="text-base">{{ item.type }}</span>
          </div>
        </div>
      </template>

      <template #item.code="{ item }">
        <div
          class="d-flex align-center gap-x-3"
          :class="{ 'row-unread': item.read === false }"
          style="cursor: pointer;"
          @click="handleRowClick(item)"
        >
          <div class="d-flex flex-column">
            <span class="text-base">{{ item.code }}</span>
          </div>
        </div>
      </template>

      <template #item.issue="{ item }">
        <div
          class="d-flex align-center gap-x-3"
          :class="{ 'row-unread': item.read === false }"
          style="cursor: pointer;"
          @click="handleRowClick(item)"
        >
          <div class="d-flex flex-column">
            <span class="text-base">{{ item.issue }}</span>
          </div>
        </div>
      </template>

      <template #item.issue_date="{ item }">
        <div
          class="d-flex align-center gap-x-3"
          :class="{ 'row-unread': item.read === false }"
          style="cursor: pointer;"
          @click="handleRowClick(item)"
        >
          <div class="d-flex flex-column">
            <span class="text-base">{{ item.issue_date }}</span>
          </div>
        </div>
      </template>

      <template #item.status="{ item }">
        <div
          class="d-flex align-center gap-x-3"
          :class="{ 'row-unread': item.read === false }"
          style="cursor: pointer;"
          @click="handleRowClick(item)"
        >
          <div class="d-flex flex-column">
            <VChip
              :color="getStatusColor(item.status)"
              outlined
            >
              {{
                status.find((s) => s.value === normalizeStatus(item.status))?.title ??
                  normalizeStatus(item.status)
              }}
            </VChip>
          </div>
        </div>
      </template>

      <!-- Pagination -->
      <template #bottom>
        <VDivider />

        <div class="d-flex justify-end flex-wrap gap-x-6 px-2 py-1">
          <div class="d-flex align-center gap-x-2 text-medium-emphasis text-base">
            Registros por página:
            <VSelect
              v-model="itemsPerPage"
              class="per-page-select"
              variant="plain"
              :items="[10, 20, 25, 50, 100]"
            />
          </div>

          <p class="d-flex align-center text-base text-high-emphasis me-2 mb-0">
            {{ paginationMeta({ page, itemsPerPage }, total) }}
          </p>

          <div class="d-flex gap-x-2 align-center me-2">
            <VBtn
              class="flip-in-rtl"
              icon="ri-arrow-left-s-line"
              variant="text"
              density="comfortable"
              color="high-emphasis"
              :disabled="page <= 1"
              @click="page <= 1 ? (page = 1) : page--"
            />

            <VBtn
              class="flip-in-rtl"
              icon="ri-arrow-right-s-line"
              density="comfortable"
              variant="text"
              color="high-emphasis"
              :disabled="page >= Math.ceil(total / itemsPerPage)"
              @click="
                page >= Math.ceil(total / itemsPerPage)
                  ? (page = Math.ceil(total / itemsPerPage))
                  : page++
              "
            />
          </div>
        </div>
      </template>
    </VDataTableServer>
  </VCard>

  <OCDetailDialog
    v-model:is-dialog-visible="isDialogVisible"
    :company="selectedItem?.company"
    :code="selectedItem?.code"
    :type="selectedItem?.type"
    :module="selectedItem?.module"
    :status="normalizeStatus(selectedItem?.status)"
    :pre-approved-by="selectedItem?.pre_approved_by"
    :pre-approved-at="selectedItem?.pre_approved_at"
    @show-snackbar="showSnackbar"
    @refresh="fetchOcs"
  />

  <VSnackbar
    v-model="isSnackbarVisible"
    :timeout="3000"
    location="top"
    :color="colorSnackbar"
  >
    {{ messageSnackbar }}
  </VSnackbar>
</template>

<style lang="scss" scoped>
.app-user-search-filter {
  inline-size: 15.625rem;
}

.row-unread {
  font-weight: bold;
}
</style>

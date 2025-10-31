const apiBase = '';

const state = {
  dealId: null,
  trucks: [],
  materials: [],
  drivers: [],
  hauls: [],
  embedded: window !== window.top,
};

const elements = {
  dealInput: document.getElementById('deal-id-input'),
  dealLabel: document.getElementById('deal-label'),
  loadButton: document.getElementById('load-hauls'),
  haulForm: document.getElementById('haul-form'),
  tableBody: document.getElementById('haul-table-body'),
  truckSelect: document.getElementById('truck-select'),
  materialSelect: document.getElementById('material-select'),
  driverSelect: document.getElementById('driver-select'),
};

async function init() {
  configureEmbedding();
  await loadReferenceData();
  await detectDealId();
  if (state.dealId) {
    await loadHauls();
  }

  elements.loadButton.addEventListener('click', () => {
    const dealId = Number(elements.dealInput.value);
    if (!dealId) {
      alert('Введите корректный ID сделки');
      return;
    }
    setDealId(dealId);
    loadHauls();
  });

  elements.haulForm.addEventListener('submit', onSubmitForm);
}

function configureEmbedding() {
  if (state.embedded) {
    const controls = document.querySelector('.controls');
    if (controls) controls.style.display = 'none';
    if (elements.loadButton) elements.loadButton.style.display = 'none';
    document.body.classList.add('embedded');
  }
}

async function detectDealId() {
  const searchParams = new URLSearchParams(window.location.search);
  const candidate = searchParams.get('dealId') || searchParams.get('deal_id');

  if (candidate) {
    const numericId = Number(candidate);
    if (Number.isFinite(numericId)) {
      setDealId(numericId);
      return;
    }
  }

  if (state.embedded && window.BX24) {
    await new Promise((resolve) => {
      window.BX24.init(() => {
        window.BX24.getPageParams((params) => {
          const possible = params?.deal_id || params?.ID || params?.entity_id;
          const numericId = Number(possible);
          if (Number.isFinite(numericId)) {
            setDealId(numericId);
          }
          window.BX24?.fitWindow?.();
          resolve();
        });
      });
    });
  }
}

function setDealId(id) {
  state.dealId = id;
  elements.dealInput.value = id;
  elements.dealLabel.textContent = `#${id}`;
}

async function loadReferenceData() {
  try {
    const [trucks, materials, drivers] = await Promise.all([
      request('/api/trucks'),
      request('/api/materials'),
      request('/api/drivers'),
    ]);
    state.trucks = trucks.data || [];
    state.materials = materials.data || [];
    state.drivers = drivers.data || [];
    renderSelect(elements.truckSelect, state.trucks, 'license_plate');
    renderSelect(elements.materialSelect, state.materials, 'name');
    renderDrivers(elements.driverSelect, state.drivers);
  } catch (error) {
    console.error('Не удалось загрузить справочники', error);
  }
}

async function loadHauls() {
  if (!state.dealId) return;

  try {
    const response = await request(`/api/deals/${state.dealId}/hauls`);
    state.hauls = response.data || [];
    renderTable();
  } catch (error) {
    alert('Ошибка загрузки рейсов');
    console.error(error);
  }
}

function renderSelect(select, items, labelField) {
  select.innerHTML = '';
  if (!items.length) {
    const option = document.createElement('option');
    option.textContent = '--- нет данных ---';
    option.value = '';
    select.appendChild(option);
    select.disabled = true;
    return;
  }

  select.disabled = false;
  items.forEach((item) => {
    const option = document.createElement('option');
    option.value = item.id;
    option.textContent = item[labelField] || item.id;
    select.appendChild(option);
  });
}

function renderDrivers(select, drivers) {
  select.innerHTML = '';

  const placeholder = document.createElement('option');
  placeholder.value = '';
  placeholder.textContent = 'Выберите водителя';
  placeholder.disabled = true;
  placeholder.selected = true;
  select.appendChild(placeholder);

  if (!drivers.length) {
    select.disabled = true;
    return;
  }

  select.disabled = false;
  drivers.forEach((driver) => {
    const option = document.createElement('option');
    option.value = driver.id;
    const parts = [driver.name];
    if (driver.position) {
      parts.push(driver.position);
    }
    option.textContent = parts.join(' · ');
    select.appendChild(option);
  });
}

function renderTable() {
  elements.tableBody.innerHTML = '';

  if (!state.hauls.length) {
    const row = document.createElement('tr');
    const cell = document.createElement('td');
    cell.colSpan = 7;
    cell.textContent = 'Рейсы не найдены';
    cell.className = 'note';
    row.appendChild(cell);
    elements.tableBody.appendChild(row);
    return;
  }

  state.hauls.forEach((haul, index) => {
    const row = document.createElement('tr');

    const cells = [
      index + 1,
      lookupDriver(haul.responsible_id),
      lookupLabel(state.trucks, haul.truck_id, 'license_plate'),
      lookupLabel(state.materials, haul.material_id, 'name'),
      haul.load.volume ?? '-',
      haul.load.address_text,
      haul.unload.address_text,
    ];

    cells.forEach((value) => {
      const cell = document.createElement('td');
      cell.textContent = value ?? '';
      row.appendChild(cell);
    });

    const actions = document.createElement('td');
    actions.className = 'actions';

    const deleteBtn = document.createElement('button');
    deleteBtn.type = 'button';
    deleteBtn.textContent = 'Удалить';
    deleteBtn.addEventListener('click', () => deleteHaul(haul.id));
    actions.appendChild(deleteBtn);

    row.appendChild(actions);
    elements.tableBody.appendChild(row);
  });

  fitWindow();
}

function lookupLabel(collection, id, field) {
  const item = collection.find((entry) => entry.id === id);
  return item ? item[field] : id ?? '-';
}

function lookupDriver(id) {
  if (!id) {
    return '-';
  }
  const driver = state.drivers.find((entry) => entry.id === id);
  return driver ? driver.name : id;
}

async function onSubmitForm(event) {
  event.preventDefault();
  if (!state.dealId) {
    alert('Сначала укажите ID сделки и загрузите рейсы.');
    return;
  }

  const formData = new FormData(elements.haulForm);
  const payload = Object.fromEntries(formData.entries());

  payload.responsible_id = toNumberOrNull(payload.responsible_id);
  payload.truck_id = payload.truck_id || null;
  payload.material_id = payload.material_id || null;
  payload.load_address_text = payload.load_address_text?.trim() || '';
  payload.load_address_url = payload.load_address_url?.trim() || null;
  payload.load_from_company_id = toNumberOrNull(payload.load_from_company_id);
  payload.load_to_company_id = toNumberOrNull(payload.load_to_company_id);
  payload.load_volume = toNumberOrNull(payload.load_volume);
  payload.unload_address_text = payload.unload_address_text?.trim() || '';
  payload.unload_address_url = payload.unload_address_url?.trim() || null;
  payload.unload_from_company_id = toNumberOrNull(payload.unload_from_company_id);
  payload.unload_to_company_id = toNumberOrNull(payload.unload_to_company_id);
  payload.unload_contact_name = payload.unload_contact_name?.trim() || null;
  payload.unload_contact_phone = payload.unload_contact_phone?.trim() || null;
  payload.load_documents = [];
  payload.unload_documents = [];

  try {
    const response = await request(`/api/deals/${state.dealId}/hauls`, {
      method: 'POST',
      body: JSON.stringify(payload),
    });

    state.hauls.push(response.data);
    renderTable();
    elements.haulForm.reset();
    fitWindow();
  } catch (error) {
    alert(error.message || 'Ошибка сохранения рейса');
  }
}

async function deleteHaul(haulId) {
  if (!confirm('Удалить рейс?')) return;

  try {
    await request(`/api/hauls/${haulId}`, { method: 'DELETE' });
    state.hauls = state.hauls.filter((haul) => haul.id !== haulId);
    renderTable();
  } catch (error) {
    alert('Не удалось удалить рейс');
  }
}

function toNumberOrNull(value) {
  if (value === undefined || value === null || value === '') {
    return null;
  }
  const num = Number(value);
  return Number.isFinite(num) ? num : null;
}

function fitWindow() {
  if (state.embedded && window.BX24 && typeof window.BX24.fitWindow === 'function') {
    window.BX24.fitWindow();
  }
}

async function request(path, options = {}) {
  const response = await fetch(apiBase + path, {
    headers: {
      'Content-Type': 'application/json',
    },
    ...options,
  });

  const contentType = response.headers.get('content-type') || '';
  const data = contentType.includes('application/json') ? await response.json() : {};

  if (!response.ok) {
    const message = data.error || response.statusText;
    throw new Error(message);
  }

  return data;
}

// auto-detect dark mode
if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
  document.body.classList.add('dark');
}

init();

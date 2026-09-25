function toggleType() {
    var type = document.getElementById('keyType').value;
    var staticInput = document.getElementById('staticInput');
    var dynamicInput = document.getElementById('dynamicInput');
    var dynamicHoursInput = document.getElementById('dynamicHoursInput');

    if (staticInput) staticInput.style.display = (type === 'static') ? 'block' : 'none';
    if (dynamicInput) dynamicInput.style.display = (type === 'dynamic') ? 'block' : 'none';
    if (dynamicHoursInput) dynamicHoursInput.style.display = (type === 'dynamic_hours') ? 'block' : 'none';
}

function toggleCustomHours() {
    var select = document.getElementById('dynamicHoursSelect');
    var customWrapper = document.getElementById('customHoursWrapper');
    if (select && customWrapper) {
        customWrapper.style.display = (select.value === 'custom') ? 'block' : 'none';
    }
}
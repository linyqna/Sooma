document.addEventListener('DOMContentLoaded', () => {
    const modal = document.getElementById('scheduleModal');
    const modalTitle = document.getElementById('modalTitle');
    const closeModalBtn = document.getElementById('closeScheduleModal');
    const cancelScheduleBtn = document.getElementById('cancelSchedule');
    const scheduleForm = document.getElementById('scheduleForm');
    const modalDateEl = document.getElementById('modalDate');
    const weekLabel = document.getElementById('weekLabel');
    const prevWeekBtn = document.getElementById('previousWeek');
    const nextWeekBtn = document.getElementById('nextWeek');
    const calendarDays = document.getElementById('calendarDays');
    const calendarBody = document.getElementById('calendarBody');
    const timeLabels = document.getElementById('timeLabels');
    const classSelect = document.getElementById('classSelect');
    const classPriceInput = document.getElementById('classPriceInput');
    const formErrorEl = document.getElementById('formError');
    const toastEl = document.getElementById('scheduleToast');

    if (!calendarBody || !weekLabel) {
        console.error('Elemen kalender utama tidak ditemukan di DOM.');
        return;
    }

    const coachColors = {
        Bintari: '#A3B2A1',
        Grace: '#FF9F50',
        Alifa: '#F4D66D',
        Bunga: '#A98BC4',
        Ulung: '#EFA2A8',
        Vanes: '#65AFA5'
    };

    let schedules = [];
    let selectedDate = '';
    let selectedTime = '';

    const today = new Date();
    today.setHours(0, 0, 0, 0);

    function showToast(message) {
        if (!toastEl) {
            alert(message);
            return;
        }
        toastEl.textContent = message;
        toastEl.classList.add('show');
        setTimeout(() => {
            toastEl.classList.remove('show');
        }, 3000);
    }

    function syncPriceFromClass() {
        if (!classSelect || !classPriceInput) return;
        const selectedOption = classSelect.options[classSelect.selectedIndex];
        if (selectedOption && selectedOption.dataset.price) {
            classPriceInput.value = selectedOption.dataset.price;
        }
    }

    if (classSelect) {
        classSelect.addEventListener('change', syncPriceFromClass);
        syncPriceFromClass();
    }

    function getStartOfWeek(date) {
        const start = new Date(date);
        const day = start.getDay();
        const diff = day === 0 ? -6 : 1 - day;

        start.setDate(start.getDate() + diff);
        start.setHours(0, 0, 0, 0);

        return start;
    }

    let currentDate = getStartOfWeek(today);

    const startHour = 8;
    const endHour = 16;
    const hours = [];
    for (let i = startHour; i <= endHour; i++) {
        hours.push(`${String(i).padStart(2, '0')}:00`);
    }

    function formatDateForStorage(date) {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');

        return `${year}-${month}-${day}`;
    }

    function formatDateForModal(dateString) {
        const [year, month, day] = dateString.split('-').map(Number);
        const date = new Date(year, month - 1, day);

        return date.toLocaleDateString('en-GB', {
            weekday: 'long',
            day: 'numeric',
            month: 'short',
            year: 'numeric'
        });
    }

    function timeToHoursFloat(timeStr) {
        const [h, m] = timeStr.split(':').map(Number);
        return h + (m || 0) / 60;
    }

    function renderTimeLabels() {
        if (timeLabels) {
            timeLabels.innerHTML = hours
                .map(hour => `<div class="time-label">${hour}</div>`)
                .join('');
        }
    }

    function getWeekDates(startDate) {
        const monday = getStartOfWeek(startDate);
        const dates = [];

        for (let i = 0; i < 5; i++) {
            const date = new Date(monday);
            date.setDate(monday.getDate() + i);
            dates.push(date);
        }

        return dates;
    }

    async function loadSchedules() {
        try {
            const res = await fetch('booking-schedule.php');
            const json = await res.json();
            if (json.success && Array.isArray(json.data)) {
                schedules = json.data;
            }
        } catch (err) {
            console.error('Failed to load schedules:', err);
        }
        renderCalendar();
    }

    function renderCalendar() {
        renderTimeLabels();

        const weekDates = getWeekDates(currentDate);
        const weekDateStrings = weekDates.map(formatDateForStorage);

        weekLabel.textContent =
            `${weekDates[0].toLocaleDateString('en-GB', {
                day: 'numeric',
                month: 'short',
                year: 'numeric'
            })} - ${weekDates[4].toLocaleDateString('en-GB', {
                day: 'numeric',
                month: 'short',
                year: 'numeric'
            })}`;

        if (calendarDays) {
            calendarDays.innerHTML = weekDates.map(date => {
                const isToday = date.getTime() === today.getTime();
                const formattedDay = date.toLocaleDateString('en-GB', {
                    weekday: 'long',
                    day: 'numeric'
                });

                return `<div class="calendar-day-header ${isToday ? 'is-today' : ''}">${formattedDay}</div>`;
            }).join('');
        }

        // 1. Render grid sel kosong
        const gridCellsHtml = hours.map(hour =>
            weekDates.map(date => {
                const dateStr = formatDateForStorage(date);
                const isPast = date < today;

                return `
                    <div
                        class="calendar-cell ${isPast ? 'is-past' : ''}"
                        data-date="${dateStr}"
                        data-time="${hour}"
                        tabindex="0"
                        role="button"
                        aria-label="Add schedule on ${dateStr} at ${hour}">
                    </div>
                `;
            }).join('')
        ).join('');

        // 2. Render blok .schedule-event berwarna sesuai coach & posisi jam
        const eventsHtml = schedules
            .filter(item => weekDateStrings.includes(item.date))
            .map(item => {
                const colIndex = weekDateStrings.indexOf(item.date);
                const startFloat = timeToHoursFloat(item.startTime);
                const endFloat = timeToHoursFloat(item.endTime);

                const startOffset = Math.max(0, startFloat - startHour);
                const duration = Math.max(0.5, endFloat - startFloat);
                const bgColor = coachColors[item.instructor] || '#A3B2A1';

                return `
                    <div
                        class="schedule-event"
                        data-schedule-id="${item.scheduleId}"
                        style="--col: ${colIndex}; --start: ${startOffset}; --len: ${duration}; --coach-color: ${bgColor};"
                        title="${item.className} - Coach ${item.instructor} (${item.startTime} - ${item.endTime})">
                        <div class="event-title">${item.className}</div>
                        <small>${item.bookedCount}/${item.memberSlot} Participants</small>
                    </div>
                `;
            }).join('');

        calendarBody.innerHTML = gridCellsHtml + eventsHtml;

        if (prevWeekBtn) prevWeekBtn.disabled = false;
        if (nextWeekBtn) nextWeekBtn.disabled = false;
    }

    function openAddModal(dateString, timeString) {
        if (!modal || !scheduleForm) return;

        selectedDate = dateString;
        selectedTime = timeString;

        if (modalTitle) modalTitle.textContent = 'Add New Schedule';
        if (formErrorEl) {
            formErrorEl.hidden = true;
            formErrorEl.textContent = '';
        }

        scheduleForm.reset();
        modalDateEl.textContent = formatDateForModal(selectedDate);
        scheduleForm.elements.scheduleDate.value = selectedDate;
        scheduleForm.elements.scheduleId.value = '';
        scheduleForm.elements.startTime.value = selectedTime;

        const [h, m] = selectedTime.split(':').map(Number);
        const endTime = new Date(2000, 0, 1, h, m);
        endTime.setHours(endTime.getHours() + 1);

        scheduleForm.elements.endTime.value =
            `${String(endTime.getHours()).padStart(2, '0')}:${String(endTime.getMinutes()).padStart(2, '0')}`;

        syncPriceFromClass();
        modal.hidden = false;
        document.body.classList.add('modal-open');
    }

    function openEditModal(scheduleItem) {
        if (!modal || !scheduleForm || !scheduleItem) return;

        selectedDate = scheduleItem.date;
        selectedTime = scheduleItem.startTime;

        if (modalTitle) modalTitle.textContent = 'Edit Schedule';
        if (formErrorEl) {
            formErrorEl.hidden = true;
            formErrorEl.textContent = '';
        }

        modalDateEl.textContent = formatDateForModal(scheduleItem.date);
        scheduleForm.elements.scheduleDate.value = scheduleItem.date;
        scheduleForm.elements.scheduleId.value = scheduleItem.scheduleId;
        scheduleForm.elements.classId.value = scheduleItem.classId;
        scheduleForm.elements.classType.value = scheduleItem.classType;
        scheduleForm.elements.instructorId.value = scheduleItem.instructorId;
        scheduleForm.elements.memberSlot.value = scheduleItem.memberSlot;
        scheduleForm.elements.startTime.value = scheduleItem.startTime;
        scheduleForm.elements.endTime.value = scheduleItem.endTime;
        scheduleForm.elements.level.value = scheduleItem.level;

        syncPriceFromClass();
        modal.hidden = false;
        document.body.classList.add('modal-open');
    }

    function closeModal() {
        if (modal) modal.hidden = true;
        document.body.classList.remove('modal-open');
        if (scheduleForm) {
            scheduleForm.reset();
            syncPriceFromClass();
        }
        if (formErrorEl) {
            formErrorEl.hidden = true;
            formErrorEl.textContent = '';
        }

        selectedDate = '';
        selectedTime = '';
    }

    calendarBody.addEventListener('click', event => {
        const eventCard = event.target.closest('.schedule-event');
        if (eventCard) {
            const scheduleId = Number(eventCard.dataset.scheduleId);
            const found = schedules.find(s => s.scheduleId === scheduleId);
            if (found) openEditModal(found);
            return;
        }

        const cell = event.target.closest('.calendar-cell');
        if (!cell) return;
        openAddModal(cell.dataset.date, cell.dataset.time);
    });

    calendarBody.addEventListener('keydown', event => {
        if (event.key !== 'Enter' && event.key !== ' ') return;
        const cell = event.target.closest('.calendar-cell');
        if (!cell) return;

        event.preventDefault();
        openAddModal(cell.dataset.date, cell.dataset.time);
    });

    if (closeModalBtn) {
        closeModalBtn.addEventListener('click', closeModal);
    }

    if (cancelScheduleBtn) {
        cancelScheduleBtn.addEventListener('click', closeModal);
    }

    if (scheduleForm) {
        scheduleForm.addEventListener('submit', async event => {
            event.preventDefault();

            if (!selectedDate || !selectedTime) {
                showToast('Please select a calendar cell first.');
                return;
            }

            const formData = new FormData(scheduleForm);
            formData.set('scheduleDate', selectedDate);
            formData.set('action', formData.get('scheduleId') ? 'update' : 'create');

            try {
                const res = await fetch('booking-schedule.php', {
                    method: 'POST',
                    body: formData
                });
                const json = await res.json();

                if (!json.success) {
                    if (formErrorEl) {
                        formErrorEl.textContent = json.message || 'Failed to save schedule.';
                        formErrorEl.hidden = false;
                    } else {
                        showToast(json.message || 'Failed to save schedule.');
                    }
                    return;
                }

                closeModal();
                await loadSchedules();
                showToast(json.message || 'New Schedule is Added!');
            } catch (err) {
                console.error('Error submitting schedule:', err);
                showToast('Network or server error while saving schedule.');
            }
        });
    }

    if (prevWeekBtn) {
        prevWeekBtn.addEventListener('click', () => {
            currentDate.setDate(currentDate.getDate() - 7);
            renderCalendar();
        });
    }

    if (nextWeekBtn) {
        nextWeekBtn.addEventListener('click', () => {
            currentDate.setDate(currentDate.getDate() + 7);
            renderCalendar();
        });
    }

    loadSchedules();
});
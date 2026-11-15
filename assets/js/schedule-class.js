
document.addEventListener('DOMContentLoaded', () => {
    const modal = document.getElementById('scheduleModal');
    const closeModalBtn = document.getElementById('closeScheduleModal');
    const cancelScheduleBtn = document.getElementById('cancelSchedule');
    const saveScheduleBtn = document.getElementById('saveSchedule');
    const scheduleForm = document.getElementById('scheduleForm');

    const modalTitle = document.getElementById('modalTitle');
    const modalDateEl = document.getElementById('modalDate');
    const scheduleToast = document.getElementById('scheduleToast');

    const weekLabel = document.getElementById('weekLabel');
    const prevWeekBtn = document.getElementById('previousWeek');
    const nextWeekBtn = document.getElementById('nextWeek');

    const calendarDays = document.getElementById('calendarDays');
    const calendarBody = document.getElementById('calendarBody');
    const timeLabels = document.getElementById('timeLabels');

    const API_URL = 'booking-schedule.php';

    const requiredElements = [
        closeModalBtn,
        cancelScheduleBtn,
        saveScheduleBtn,
        modalTitle,
        modalDateEl,
        weekLabel,
        prevWeekBtn,
        nextWeekBtn,
        calendarDays,
        timeLabels
    ];

    if (
        !calendarBody ||
        !scheduleForm ||
        !modal ||
        requiredElements.some(element => !element)
    ) {
        console.error('Required calendar elements not found.');
        return;
    }

    let schedules = [];
    let selectedDate = '';
    let selectedTime = '';
    let selectedSchedule = null;

    let toastTimeout;

    const coachColors = {
        Bintari: '#A3B2A1',
        Grace: '#FF9F50',
        Alifa: '#F4D66D',
        Bunga: '#A98BC4',
        Ulung: '#EFA2A8',
        Vanes: '#65AFA5'
    };

    const today = new Date();
    today.setHours(0, 0, 0, 0);

    let currentDate = getStartOfWeek(today);

    const hours = [];

    for (let i = 8; i <= 16; i++) {
        hours.push(`${String(i).padStart(2, '0')}:00`);
    }

    function escapeHTML(value) {
        return String(value ?? '').replace(/[&<>"']/g, char => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        })[char]);
    }

    function getCoachColor(instructor) {
        const name = String(instructor ?? '')
            .replace(/^Coach\s+/i, '')
            .trim();

        return coachColors[name] || '#A3B2A1';
    }

    function getStartOfWeek(date) {
        const start = new Date(date);
        const day = start.getDay();
        const diff = day === 0 ? -6 : 1 - day;

        start.setDate(start.getDate() + diff);
        start.setHours(0, 0, 0, 0);

        return start;
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

    function renderTimeLabels() {
        timeLabels.innerHTML = hours
            .map(hour => `<div class="time-label">${hour}</div>`)
            .join('');
    }

    async function loadSchedules() {
        try {
            const response = await fetch(API_URL);
            const result = await response.json();

            if (!response.ok || !result.success) {
                throw new Error(result.message || 'Unable to load schedules.');
            }

            schedules = result.data || [];
            renderCalendar();

        } catch (error) {
            console.error(error);
            showToast('Unable to load schedules.');
        }
    }

    function renderCalendar() {
        renderTimeLabels();

        const weekDates = getWeekDates(currentDate);

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

        calendarDays.innerHTML = weekDates.map(date => {
            const formattedDay = date.toLocaleDateString('en-GB', {
                weekday: 'long',
                day: 'numeric'
            });

            return `<div class="calendar-day-header">${formattedDay}</div>`;
        }).join('');

        calendarBody.innerHTML = hours.map(hour =>
            weekDates.map(date => {
                const dateStr = formatDateForStorage(date);

                return `
                    <div
                        class="calendar-cell"
                        data-date="${dateStr}"
                        data-time="${hour}"
                        tabindex="0"
                        role="button"
                        aria-label="Add schedule on ${dateStr} at ${hour}">
                    </div>
                `;
            }).join('')
        ).join('');

        schedules.forEach(schedule => {
            const dayIndex = weekDates.findIndex(
                date => formatDateForStorage(date) === schedule.date
            );

            if (dayIndex === -1 || !schedule.startTime || !schedule.endTime) {
                return;
            }

            const [startHour, startMinute] = schedule.startTime.split(':').map(Number);
            const [endHour, endMinute] = schedule.endTime.split(':').map(Number);

            const startMinutes = startHour * 60 + startMinute;
            const endMinutes = endHour * 60 + endMinute;

            const calendarStart = 8 * 60;
            const calendarEnd = 17 * 60;

            if (
                endMinutes <= startMinutes ||
                startMinutes < calendarStart ||
                endMinutes > calendarEnd
            ) {
                return;
            }

            const startPosition = (startMinutes - calendarStart) / 60;
            const duration = (endMinutes - startMinutes) / 60;

            const capacity = Number(schedule.memberSlot) || 0;
            const bookedCount = Number(schedule.bookedCount) || 0;

            const event = document.createElement('div');

            event.className = 'schedule-event';
            event.dataset.scheduleId = schedule.scheduleId;
            event.tabIndex = 0;
            event.setAttribute('role', 'button');
            event.setAttribute(
                'aria-label',
                `View ${schedule.className} schedule`
            );

            event.style.setProperty('--col', dayIndex);
            event.style.setProperty('--start', startPosition);
            event.style.setProperty('--len', duration);
            event.style.setProperty('--coach-color', getCoachColor(schedule.instructor));

            event.innerHTML = `
                <div class="schedule-event-content">
                    <strong class="event-title">${escapeHTML(schedule.className)}</strong>
                    <div class="schedule-participants">
                        ${bookedCount}/${capacity} Participants
                    </div>
                </div>
            `;

            calendarBody.appendChild(event);
        });
    }


    function openModal(dateString, timeString, schedule = null) {
        selectedDate = dateString;
        selectedTime = timeString;
        selectedSchedule = schedule;

        scheduleForm.reset();

        const fields = scheduleForm.elements;

        fields.namedItem('scheduleDate').value = dateString;
        fields.namedItem('scheduleId').value = schedule
            ? schedule.scheduleId
            : '';

        modalDateEl.textContent = formatDateForModal(dateString);

        if (schedule) {
            // Edit jadwal yang sudah tersimpan
            modalTitle.textContent = 'Schedule Details';

            fields.namedItem('className').value = schedule.className;
            fields.namedItem('classType').value = schedule.classType;
            fields.namedItem('instructor').value = schedule.instructor;
            fields.namedItem('memberSlot').value = schedule.memberSlot;
            fields.namedItem('startTime').value = schedule.startTime;
            fields.namedItem('endTime').value = schedule.endTime;
            fields.namedItem('level').value = schedule.level;
            fields.namedItem('price').value = schedule.price;

            cancelScheduleBtn.textContent = 'Delete';
            saveScheduleBtn.textContent = 'Save Changes';

        } else {
            // Tambah jadwal baru
            modalTitle.textContent = 'Add New Schedule';

            fields.namedItem('startTime').value = timeString;

            const [hour, minute] = timeString.split(':').map(Number);
            const endTime = new Date(2000, 0, 1, hour, minute);

            endTime.setHours(endTime.getHours() + 1);

            fields.namedItem('endTime').value =
                `${String(endTime.getHours()).padStart(2, '0')}:${String(endTime.getMinutes()).padStart(2, '0')}`;

            cancelScheduleBtn.textContent = 'Cancel';
            saveScheduleBtn.textContent = 'Save';
        }

        modal.hidden = false;
        document.body.classList.add('modal-open');
    }


    function closeModal() {
        modal.hidden = true;
        document.body.classList.remove('modal-open');

        scheduleForm.reset();

        selectedDate = '';
        selectedTime = '';
        selectedSchedule = null;
    }

    function showToast(message) {
        if (!scheduleToast) return;

        clearTimeout(toastTimeout);

        scheduleToast.textContent = message;
        scheduleToast.classList.add('show');

        toastTimeout = setTimeout(() => {
            scheduleToast.classList.remove('show');
        }, 3000);
    }

    async function sendRequest(formData) {
        const response = await fetch(API_URL, {
            method: 'POST',
            body: formData
        });

        const result = await response.json();

        if (!response.ok || !result.success) {
            throw new Error(result.message || 'Something went wrong.');
        }

        return result;
    }

    // Klik pada slot kosong atau jadwal yang sudah tersimpan
    calendarBody.addEventListener('click', event => {
        const scheduleEvent = event.target.closest('.schedule-event');

        if (scheduleEvent) {
            const schedule = schedules.find(
                item => String(item.scheduleId) === scheduleEvent.dataset.scheduleId
            );

            if (schedule) {
                openModal(schedule.date, schedule.startTime, schedule);
            }

            return;
        }

        const cell = event.target.closest('.calendar-cell');

        if (!cell) return;

        openModal(cell.dataset.date, cell.dataset.time);
    });

    calendarBody.addEventListener('keydown', event => {
        if (event.key !== 'Enter' && event.key !== ' ') return;

        const target = event.target.closest('.schedule-event, .calendar-cell');

        if (!target) return;

        event.preventDefault();

        if (target.classList.contains('schedule-event')) {
            const schedule = schedules.find(
                item => String(item.scheduleId) === target.dataset.scheduleId
            );

            if (schedule) {
                openModal(schedule.date, schedule.startTime, schedule);
            }
        } else {
            openModal(target.dataset.date, target.dataset.time);
        }
    });

    // Close modal
    closeModalBtn.addEventListener('click', closeModal);

    modal.addEventListener('click', event => {
        if (event.target === modal) {
            closeModal();
        }
    });

    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && !modal.hidden) {
            closeModal();
        }
    });

    // Cancel untuk jadwal baru, Delete untuk jadwal tersimpan
    
// Cancel untuk jadwal baru, Delete untuk jadwal tersimpan
cancelScheduleBtn.addEventListener('click', async () => {
    if (!selectedSchedule) {
        closeModal();
        return;
    }

    const confirmed = confirm(
        'Are you sure you want to delete this schedule?'
    );

    if (!confirmed) return;

    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('scheduleId', selectedSchedule.scheduleId);

    cancelScheduleBtn.disabled = true;

    try {
        await sendRequest(formData);

        closeModal();
        await loadSchedules();

        showToast('Schedule has been deleted.');

    } catch (error) {
        console.error(error);
        showToast(error.message);

    } finally {
        cancelScheduleBtn.disabled = false;
    }
});

// Save / Save Changes
scheduleForm.addEventListener('submit', async event => {
    event.preventDefault();

    if (!selectedDate) {
        showToast('Please select a calendar slot first.');
        return;
    }

    const startTime = scheduleForm.elements.startTime.value;
    const endTime = scheduleForm.elements.endTime.value;

    if (
        !startTime ||
        !endTime ||
        startTime < '08:00' ||
        endTime > '17:00' ||
        endTime <= startTime
    ) {
        showToast('Please enter a valid schedule between 08:00 and 17:00.');
        return;
    }

    const memberSlot = Number(
        scheduleForm.elements.memberSlot.value
    );

    if (
        !Number.isInteger(memberSlot) ||
        memberSlot < 1 ||
        memberSlot > 30
    ) {
        showToast('Member capacity must be between 1 and 30.');
        return;
    }

    const isEditing = Boolean(selectedSchedule);
    const formData = new FormData(scheduleForm);

    formData.append('action', isEditing ? 'update' : 'create');

    saveScheduleBtn.disabled = true;
    saveScheduleBtn.textContent = 'Saving...';

    try {
        await sendRequest(formData);

        closeModal();
        await loadSchedules();

        showToast(
            isEditing
                ? 'Schedule has been updated.'
                : 'New Schedule is Added!'
        );

    } catch (error) {
        console.error(error);
        showToast(error.message);

    } finally {
        saveScheduleBtn.disabled = false;
        saveScheduleBtn.textContent = 'Save';
    }
});

// Week navigation
prevWeekBtn.addEventListener('click', () => {
    currentDate.setDate(currentDate.getDate() - 7);
    renderCalendar();
});

nextWeekBtn.addEventListener('click', () => {
    currentDate.setDate(currentDate.getDate() + 7);
    renderCalendar();
});

renderCalendar();

loadSchedules();

});
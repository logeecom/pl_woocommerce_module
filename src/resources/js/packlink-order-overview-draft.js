var Packlink = window.Packlink || {};

document.addEventListener(
	'DOMContentLoaded',
	function () {
		let createDraftEndpoint = document.querySelector('#pl-create-endpoint'),
			checkDraftStatusEndpoint = document.querySelector('#pl-check-status'),
			draftInProgressMessage = document.querySelector('#pl-draft-in-progress'),
			draftFailedMessage = document.querySelector('#pl-draft-failed'),
			draftAbortedMessage = document.querySelector('#pl-draft-aborted'),
			draftButtonTemplate = document.querySelector('#pl-draft-button-template'),
			createDraftTemplate = document.querySelector('#pl-create-draft-template'),
			createDraftButtons = document.getElementsByClassName('pl-create-draft-button'),
			draftsInProgress = document.getElementsByClassName('pl-draft-in-progress');

		for (let createDraftButton of createDraftButtons) {
			createDraftButton.addEventListener('click', function (event) {
				event.preventDefault();

				createDraft(createDraftButton);
			});
		}

		for (let draftInProgress of draftsInProgress) {
			let orderId = draftInProgress.getAttribute('data-order-id'),
				parent = draftInProgress.parentElement;

			checkDraftStatus(parent, orderId);
		}

		function createDraft(createDraftButton) {
			let orderId = parseInt(createDraftButton.getAttribute('data-order-id')),
				buttonParent = createDraftButton.parentElement;

			buttonParent.removeChild(createDraftButton);
			buttonParent.innerText = draftInProgressMessage.value;

			Packlink.ajaxService.post(createDraftEndpoint.value, {id: orderId}, function () {
				checkDraftStatus(buttonParent, orderId);
			});
		}

		function checkDraftStatus(parent, orderId) {
			clearTimeout(function () {
				checkDraftStatus(parent, orderId);
			});

			Packlink.ajaxService.get(checkDraftStatusEndpoint.value + '&order_id=' + orderId, function (response) {
				if (response.status === 'created') {
					let viewDraftButton = draftButtonTemplate.cloneNode(true);

					viewDraftButton.id = '';
					viewDraftButton.href = response.shipment_url;
					viewDraftButton.classList.remove('hidden');

					parent.innerHTML = '';
					parent.appendChild(viewDraftButton);
				} else if (response.status === 'failed') {
					parent.innerText = draftFailedMessage.value;
					setTimeout(function () {
						displayCreateDraftButton(parent, orderId)
					}, 5000)
				} else if (response.status === 'aborted') {
					parent.innerText = draftAbortedMessage.value + ' ' + response.message;
				} else {
					setTimeout(function () {
						checkDraftStatus(parent, orderId)
					}, 1000);
				}
			});
		}

		function displayCreateDraftButton(parent, orderId) {
			clearTimeout(function () {
				displayCreateDraftButton(parent, orderId)
			});

			let createDraftButton = createDraftTemplate.cloneNode(true);

			createDraftButton.id = '';
			createDraftButton.classList.remove('hidden');
			createDraftButton.setAttribute('data-order-id', orderId);

			createDraftButton.addEventListener('click', function (event) {
				event.preventDefault();

				createDraft(createDraftButton);
			});

			parent.innerHTML = '';
			parent.appendChild(createDraftButton);
		}
	}
);

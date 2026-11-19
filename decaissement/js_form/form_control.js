document.addEventListener("DOMContentLoaded", function () {
  const approved = document.getElementById("approved-supplier-documents");
  if (approved?.dataset.approved === "1") {
    const name = document.getElementById("nom_prenom");
    const phone = document.getElementById("telephone");
    name.value = approved.dataset.name || "";
    phone.value = approved.dataset.phone || "";
    name.readOnly = phone.readOnly = true;
    phone.removeAttribute("pattern");
    const personnel = document.getElementById("beneficiaire_is_personnel");
    if (personnel) { personnel.checked = false; personnel.disabled = true; }
  }
  //Desactiver les bouttons suivants au depart
  document.getElementById("first_next_button").disabled = true;
  document.getElementById("first_next_button_2").disabled = true;
  document.getElementById("first_next_button_3").disabled = true;
  document.getElementById("first_next_button_4").disabled = true;

  // Ajoute des événements d'écoute pour les champs
  document
    .getElementById("nom_prenom")
    .addEventListener("input", toggleNextButton_1);
  document
    .getElementById("telephone")
    .addEventListener("input", toggleNextButton_1);

  // Bénéficiaire = personnel (RH)
  const personnelToggle = document.getElementById("beneficiaire_is_personnel");
  if (personnelToggle) {
    personnelToggle.addEventListener("change", toggleNextButton_1);
  }

  document
    .getElementById("affectation")
    .addEventListener("change", toggleNextButton_2);
  document
    .getElementById("montant")
    .addEventListener("change", toggleNextButton_3);
  document
    .getElementById("photo_demandeur")
    .addEventListener("change", validatePhoto);
  document
    .getElementById("cni_demandeur")
    .addEventListener("change", validateCNI);

  // Évaluer l'état initial step 4 (utile si photo/CNI viennent du RH)
  try {
    updateStep4NextButtonState();
    if (hasApprovedSupplierDocuments()) toggleNextButton_1();
  } catch (e) {}
});

function hasApprovedSupplierDocuments() {
  return document.getElementById("approved-supplier-documents")?.dataset.approved === "1";
}

function hasPersonnelPhoto() {
  if (hasApprovedSupplierDocuments()) return true;
  const el = document.getElementById("personnel_has_photo");
  const v = el && typeof el.value !== "undefined" ? el.value : "0";
  return String(v) === "1";
}

function hasPersonnelCni() {
  // Physical suppliers have a checked attachment; companies do not require one.
  if (hasApprovedSupplierDocuments()) return true;
  const el = document.getElementById("personnel_has_cni");
  const v = el && typeof el.value !== "undefined" ? el.value : "0";
  return String(v) === "1";
}

function updateStep4NextButtonState() {
  const nextButton = document.getElementById("first_next_button_4");
  if (!nextButton) return;
  const photoInput = document.getElementById("photo_demandeur");
  const cniInput = document.getElementById("cni_demandeur");
  const photoFile = photoInput && photoInput.files ? photoInput.files[0] : null;
  const cniFile = cniInput && cniInput.files ? cniInput.files[0] : null;
  const photoOk = !!photoFile || hasPersonnelPhoto();
  const cniOk = !!cniFile || hasPersonnelCni();
  nextButton.disabled = !(photoOk && cniOk);
}

// Expose pour les scripts qui mettent à jour les infos RH
try {
  window.updateStep4NextButtonState = updateStep4NextButtonState;
} catch (e) {}

function validateName() {
  const phoneInput = document.getElementById("nom_prenom");
  const errorMessage = document.getElementById("name-error-message");
  const succesMessage = document.getElementById("name-succes-message");

  // Vérifie si le champ n'est pas vide
  const phoneValue = phoneInput.value.trim(); // Trim pour éliminer les espaces inutiles

  if (!phoneValue) {
    // Si le champ est vide
    errorMessage.style.display = "block";
    succesMessage.style.display = "none";
    return false;
  } else {
    // Si une valeur est saisie
    errorMessage.style.display = "none";
    succesMessage.style.display = "block";
    return true;
  }
}

function validatePhoneNumber() {
  const phoneInput = document.getElementById("telephone");
  const errorMessage = document.getElementById("tel-error-message");
  const succesMessage = document.getElementById("tel-succes-message");

  // Vérifie si la valeur contient exactement 10 chiffres
  const phoneValue = phoneInput.value;
  const regex = /^\d{10}$/;
  if (hasApprovedSupplierDocuments() && phoneValue.trim()) {
    errorMessage.style.display = "none";
    succesMessage.style.display = "block";
    return true;
  }

  if (!regex.test(phoneValue)) {
    errorMessage.style.display = "block";
    succesMessage.style.display = "none";
    return false;
  } else {
    errorMessage.style.display = "none";
    succesMessage.style.display = "block";
    return true;
  }
}

//Vérifier si une affectation a été choisie
function validateAffectation() {
  const affectationSelect = document.getElementById("affectation");
  const affectationErrorMessage = document.getElementById(
    "affectation-error-message",
  );
  const affectationSuccessMessage = document.getElementById(
    "affectation-succes-message",
  );

  // Vérifie si une valeur est sélectionnée
  if (affectationSelect.value === "") {
    affectationErrorMessage.style.display = "block";
    affectationSuccessMessage.style.display = "none";
    return false; // Validation échouée
  } else {
    affectationErrorMessage.style.display = "none";
    affectationSuccessMessage.style.display = "block";
    return true; // Validation réussie
  }
}

// Vérifier si un montant valable a été saisi
function validateMontant() {
  const montant = document.getElementById("montant");
  const montantErrorMessage = document.getElementById("montant-error-message");
  const montantSuccessMessage = document.getElementById(
    "montant-succes-message",
  );

  // Vérifie si la valeur est un nombre valide (pas de zéro initial)
  const montantValue = montant.value.trim();
  const regex = /^[1-9]\d*$/; // Accepte seulement des chiffres, ne commençant pas par 0

  if (!regex.test(montantValue)) {
    montantErrorMessage.style.display = "block";
    montantSuccessMessage.style.display = "none";
    return false;
  } else {
    montantErrorMessage.style.display = "none";
    montantSuccessMessage.style.display = "block";
    return true;
  }
}

// Vérifie si un fichier valide a été sélectionné comme photo
function validatePhoto() {
  const photoInput = document.getElementById("photo_demandeur");
  const errorMessage = document.getElementById("photo_demandeur-error-message");
  const successMessage = document.getElementById(
    "photo_demandeur-succes-message",
  );
  const preview = document.getElementById("photo-preview");

  // Récupère le fichier sélectionné
  const file = photoInput.files[0];

  if (hasApprovedSupplierDocuments()) {
    if (errorMessage) errorMessage.style.display = "none";
    updateStep4NextButtonState();
    return true;
  }
  // Photo RH disponible => considéré comme OK si aucun fichier uploadé
  if (!file && hasPersonnelPhoto()) {
    if (errorMessage) errorMessage.style.display = "none";
    if (successMessage) {
      successMessage.style.display = "block";
      successMessage.innerHTML =
        '<i class="fa fa-check-circle"></i> Photo récupérée depuis le RH.';
    }
    // Ne touche pas au preview si déjà géré côté script.js
    updateStep4NextButtonState();
    return true;
  }

  // Vérifie si un fichier a été sélectionné
  if (!file) {
    errorMessage.style.display = "block";
    successMessage.style.display = "none";
    preview.innerHTML = ""; // Supprime l'aperçu
    updateStep4NextButtonState();
    return false;
  }

  // Vérifie les formats autorisés
  const allowedFormats = ["image/jpeg", "image/png", "image/jpg"];
  if (!allowedFormats.includes(file.type)) {
    errorMessage.textContent =
      "Format invalide. Veuillez sélectionner une image (jpg, jpeg, png).";
    errorMessage.style.display = "block";
    successMessage.style.display = "none";
    preview.innerHTML = ""; // Supprime l'aperçu
    updateStep4NextButtonState();
    return false;
  }

  // Si tout est valide, affiche l'aperçu et un message de succès
  errorMessage.style.display = "none";
  successMessage.style.display = "block";

  // Génère un aperçu de l'image
  const reader = new FileReader();
  reader.onload = function (e) {
    preview.innerHTML = `<img src="${e.target.result}" alt="Aperçu de la photo" style="max-width: 150px; max-height: 150px; margin-top: 10px;">`;
  };
  reader.readAsDataURL(file);

  updateStep4NextButtonState();
  return true;
}

// Vérifie si un fichier valide a été sélectionné comme cni
function validateCNI() {
  const cniInput = document.getElementById("cni_demandeur");
  const errorMessage = document.getElementById("cni_demandeur-error-message");
  const successMessage = document.getElementById(
    "cni_demandeur-succes-message",
  );
  const preview = document.getElementById("cni-preview");

  // Récupère le fichier sélectionné
  const file = cniInput.files[0];

  if (hasApprovedSupplierDocuments()) {
    if (errorMessage) errorMessage.style.display = "none";
    if (successMessage) {
      successMessage.style.display = "block";
      successMessage.textContent = document.getElementById("approved-supplier-documents").dataset.company === "1"
        ? "Personne morale : CNI non requise." : "Pièce reprise de la fiche fournisseur.";
    }
    updateStep4NextButtonState();
    return true;
  }
  // CNI RH disponible => considéré comme OK si aucun fichier uploadé
  if (!file && hasPersonnelCni()) {
    if (errorMessage) errorMessage.style.display = "none";
    if (successMessage) {
      successMessage.style.display = "block";
      successMessage.innerHTML =
        '<i class="fa fa-check-circle"></i> CNI récupérée depuis le RH.';
    }
    updateStep4NextButtonState();
    return true;
  }

  // Vérifie si un fichier a été sélectionné
  if (!file) {
    errorMessage.style.display = "block";
    successMessage.style.display = "none";
    preview.innerHTML = ""; // Supprime l'aperçu
    updateStep4NextButtonState();
    return false;
  }

  // Vérifie les formats autorisés
  const allowedFormats = ["image/jpeg", "image/png", "image/jpg"];
  if (!allowedFormats.includes(file.type)) {
    errorMessage.textContent =
      "Format invalide. Veuillez sélectionner une image (jpg, jpeg, png).";
    errorMessage.style.display = "block";
    successMessage.style.display = "none";
    preview.innerHTML = ""; // Supprime l'aperçu
    updateStep4NextButtonState();
    return false;
  }

  // Si tout est valide, affiche l'aperçu et un message de succès
  errorMessage.style.display = "none";
  successMessage.style.display = "block";

  const reader = new FileReader();
  reader.onload = function (e) {
    preview.innerHTML = `<img src="${e.target.result}" alt="Aperçu de la CNI" style="max-width: 150px; max-height: 150px; margin-top: 10px;">`;
  };
  reader.readAsDataURL(file);

  updateStep4NextButtonState();
  return true;
}

// Fonction pour activer/désactiver le bouton suivant N° 1
function toggleNextButton_1() {
  const firstNextButton = document.getElementById("first_next_button");

  const toggle = document.getElementById("beneficiaire_is_personnel");
  const isPersonnel = toggle ? !!toggle.checked : false;
  const personnelEl = document.getElementById("personnel_id");
  const personnelId = String(personnelEl ? personnelEl.value : "").trim();
  const hint = document.getElementById("personnel-required-hint");
  if (hint) {
    hint.style.display = isPersonnel && !personnelId ? "block" : "none";
  }

  // Vérifie les deux validations
  if (
    validateName() &&
    validatePhoneNumber() &&
    (!isPersonnel || (isPersonnel && !!personnelId))
  ) {
    firstNextButton.disabled = false;
  } else {
    firstNextButton.disabled = true;
  }
}
// Fonction pour activer/désactiver le bouton suivant N° 2
function toggleNextButton_2() {
  const firstNextButton_2 = document.getElementById("first_next_button_2");

  // Vérifie les deux validations
  if (validateAffectation()) {
    firstNextButton_2.disabled = false;
  } else {
    firstNextButton_2.disabled = true;
  }
}

// Fonction pour activer/désactiver le bouton suivant N° 2
function toggleNextButton_3() {
  const firstNextButton_3 = document.getElementById("first_next_button_3");

  // Vérifie les deux validations
  if (validateMontant()) {
    firstNextButton_3.disabled = false;
  } else {
    firstNextButton_3.disabled = true;
  }
}

// Fonction pour activer/désactiver le bouton suivant N° 4
function toggleNextButton_4() {
  const firstNextButton_4 = document.getElementById("first_next_button_4");

  // Vérifie les deux validations
  if (validatePhoto() && validateCNI()) {
    firstNextButton_4.disabled = false;
  } else {
    firstNextButton_4.disabled = true;
  }
}


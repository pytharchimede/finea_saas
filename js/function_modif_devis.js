$(document).ready(function () {
  $("#exportPdfBtn").hide();

  function parseNumberFR(value) {
    if (value === null || value === undefined) return 0;
    let s = String(value).trim();
    if (!s) return 0;
    s = s.replace(/\s/g, "");
    if (s.includes(",") && s.includes(".")) {
      // ex: 1.234,56 -> 1234.56
      s = s.replace(/\./g, "").replace(",", ".");
    } else {
      s = s.replace(",", ".");
    }
    const n = parseFloat(s);
    return Number.isFinite(n) ? n : 0;
  }

  // Calcul automatique des totaux à chaque modification
  function calculerTotaux() {
    let totalHT = 0;
    $("#devisTable tbody tr").each(function () {
      if (
        $(this).hasClass("groupe-row") ||
        $(this).hasClass("sous-total-row")
      ) {
        return;
      }

      const $qteInput = $(this).find('input[name="quantite[]"]');
      const $puInput = $(this).find('input[name="prix_unitaire[]"]');
      const $totalInput = $(this).find('input[name="prix_total[]"]');
      if (
        $qteInput.length === 0 ||
        $puInput.length === 0 ||
        $totalInput.length === 0
      ) {
        return;
      }

      let quantite = parseNumberFR($qteInput.val());
      let prixUnitaire = parseNumberFR($puInput.val());
      let prixTotal = quantite * prixUnitaire;

      $totalInput.val(prixTotal.toFixed(2));
      totalHT += prixTotal;
    });
    $("#totalHT").val(totalHT.toFixed(2));
    let tva = 0;
    if ($("#tvaFacturable").is(":checked")) {
      tva = totalHT * 0.18;
    }
    $("#tva").val(tva.toFixed(2));
    $("#totalTTC").val((totalHT + tva).toFixed(2));
  }

  // Déclenche le calcul lors de la saisie
  $(document).on(
    "input",
    'input[name="quantite[]"], input[name="prix_unitaire[]"]',
    function () {
      calculerTotaux();
    },
  );

  // Recalcule aussi après ajout/suppression de lignes
  $(document).on(
    "click",
    "#addRow, .remove-row, #addGroupBtn, .remove-group, .close-group",
    function () {
      // Petite latence pour laisser le DOM se mettre à jour
      setTimeout(calculerTotaux, 0);
    },
  );

  // Déclenche le calcul lors du changement de la TVA
  $("#tvaFacturable").on("change", function () {
    calculerTotaux();
  });

  // Calcul initial au chargement
  calculerTotaux();

  $("#saveBtn").on("click", function (e) {
    e.preventDefault();

    var form = $("#devisForm")[0];
    var formData = new FormData(form);

    // Ajoute l'ID du devis (important pour la modification)
    var devisId = $("#devisId").val();
    formData.set("devisId", devisId);

    // Ajoutez les valeurs des cases à cocher
    var tvaFacturable = $("#tvaFacturable").is(":checked") ? "1" : "0";
    var publierDevis = $("#publierDevis").is(":checked") ? "1" : "0";

    // Champs principaux
    var numeroDevis = $("#numeroDevis").val();
    var delaiLivraison = $("#delaiLivraison").val();
    var dateEmission = $("#dateEmission").val();
    var dateExpiration = $("#dateExpiration").val();
    var logoFile = $("#logoUpload")[0] ? $("#logoUpload")[0].files[0] : null;
    var clientId = $("#clientSelect").val();
    var offreId = $("#offreSelect").val();
    var termesConditions = $("#termesConditions").val() || "";
    var piedDePage = $("#piedDePage").val() || "";
    var totalHT = $("#totalHT").val() || "0";
    var totalTTC = $("#totalTTC").val() || "0";
    var tva = $("#tva").val() || "0";
    var correspondant = $("#correspondant").val() || "";

    // Synchronise les titres de groupes (groupe-row) vers les lignes (groupe[])
    // afin d'éviter des groupes non sauvegardés si le titre a été modifié.
    let currentGroupTitle = "";
    $("#devisTable tbody tr").each(function () {
      if ($(this).hasClass("groupe-row")) {
        currentGroupTitle = $(this).find(".groupe-titre").val() || "";
        return;
      }
      if ($(this).hasClass("sous-total-row")) {
        return;
      }
      const $g = $(this).find('input[name="groupe[]"]');
      if ($g.length) {
        $g.val(currentGroupTitle);
      }
    });

    // Récupération des lignes de devis (désignation, prix_unitaire, quantité, unite_id, prix_total, groupe)
    var lignes = [];
    $("#devisTable tbody tr").each(function () {
      if (
        $(this).hasClass("groupe-row") ||
        $(this).hasClass("sous-total-row")
      ) {
        return;
      }

      var ligne_id = $(this).find('input[name="ligne_id[]"]').val() || "";
      var designation = $(this).find('input[name="designation[]"]').val();
      var prix_unitaire = $(this).find('input[name="prix_unitaire[]"]').val();
      var quantite = $(this).find('input[name="quantite[]"]').val();
      var unite_id = $(this).find('select[name="unite[]"]').val();
      var prix_total = $(this).find('input[name="prix_total[]"]').val();
      var groupe = $(this).find('input[name="groupe[]"]').val() || "";

      var designationClean = (designation || "").trim();
      var hasAnyValue =
        designationClean !== "" ||
        String(prix_unitaire || "").trim() !== "" ||
        String(quantite || "").trim() !== "" ||
        String(unite_id || "").trim() !== "";
      if (!hasAnyValue) {
        return;
      }

      var qteNum = parseNumberFR(quantite);
      var puNum = parseNumberFR(prix_unitaire);
      var totalNum = qteNum * puNum;

      if (designationClean !== "" && unite_id) {
        lignes.push({
          id: ligne_id,
          designation: designation,
          prix_unitaire: puNum,
          quantite: qteNum,
          unite_id: unite_id,
          prix_total: totalNum,
          groupe: groupe,
        });
      }
    });

    // Ajoute les champs principaux au FormData (pour être sûr)
    formData.set("numeroDevis", numeroDevis);
    formData.set("delaiLivraison", delaiLivraison);
    formData.set("dateEmission", dateEmission);
    formData.set("dateExpiration", dateExpiration);
    formData.set("termesConditions", termesConditions);
    formData.set("piedDePage", piedDePage);
    formData.set("totalHT", totalHT);
    formData.set("totalTTC", totalTTC);
    formData.set("tva", tva);
    formData.set("client_id", clientId);
    formData.set("offre_id", offreId);
    formData.set("tvaFacturable", tvaFacturable);
    formData.set("publierDevis", publierDevis);
    formData.set("correspondant", correspondant);

    if (logoFile) {
      // Le backend attend "logoUpload" (même name que l'input)
      formData.set("logoUpload", logoFile);
    }

    // Ajoute les lignes de devis sous forme de JSON
    formData.set("lignes", JSON.stringify(lignes));

    // Effectuez la requête AJAX
    $.ajax({
      type: "POST",
      url: "request/update_devis.php",
      data: formData,
      dataType: "json",
      cache: false,
      contentType: false,
      processData: false,
      success: function (response) {
        if (response.success) {
          $("#exportPdfBtn").show();
          $("#saveBtn").hide();
        } else {
          alert(response.message || "Erreur lors de la modification du devis");
        }
      },
      error: function (xhr, status, error) {
        console.error("Erreur:", status, error);
      },
    });
  });

  $("#exportPdfBtn").on("click", function () {
    $(location).attr("href", "request/export_pdf.php");
  });
});

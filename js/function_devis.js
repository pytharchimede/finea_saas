$(document).ready(function () {
  $("#exportPdfBtn").hide();

  $("#saveBtn").on("click", function (e) {
    e.preventDefault(); // Empêche le comportement de soumission du formulaire par défaut

    var form = $("#devisForm")[0];
    var result = $("#resultat");
    var saveButton = $("#saveBtn");
    result.html("");
    var formData = new FormData(form);

    // Ajoutez les valeurs des cases à cocher
    var tvaFacturable = $('input[name="tvaFacturable"]:checked').val() || "0";
    var publierDevis = $('input[name="publierDevis"]:checked').val() || "0";

    // Champs principaux
    var numeroDevis = $("#numeroDevis").val();
    var delaiLivraison = $("#delaiLivraison").val();
    var dateEmission = $("#dateEmission").val();
    var dateExpiration = $("#dateExpiration").val();
    var logoFile = $("#logoUpload")[0] ? $("#logoUpload")[0].files[0] : null;
    var clientId = $("#clientSelect").val();
    var offreId = $("#offreSelect").val();
    var projectMode = $('input[name="devis_project_mode"]:checked').val() || "NEW_PROJECT";
    var newOfferNumber = $("#newOfferNumber").val() || "";
    var newOfferReference = $("#newOfferReference").val() || "";
    var termesConditions = $("#termesConditions").val() || "";
    var piedDePage = $("#piedDePage").val() || "";
    var totalHT = $("#totalHT").val() || "0";
    var totalTTC = $("#totalTTC").val() || "0";
    var billingDate = $("#billingDate").val() || "";
    var commercialDiscount = $("#commercialDiscount").val() || "0";
    var tva = $("#tva").val() || "0";
    var correspondant = $("#correspondant").val() || "";

    // Récupération des lignes de devis (désignation, prix, quantité, unite_id, total)
    var lignes = [];
    $("#devisTable tbody tr").each(function () {
      var designation = $(this).find('input[name="designation[]"]').val();
      var prix = $(this).find('input[name="prix[]"]').val();
      var quantite = $(this).find('input[name="quantite[]"]').val();
      var unite_id = $(this).find('select[name="unite[]"]').val();
      var total = $(this).find('input[name="total[]"]').val();
      var groupe = $(this).find('input[name="groupe[]"]').val() || "";
      if (designation && prix && quantite && unite_id) {
        lignes.push({
          designation: designation,
          prix: prix,
          quantite: quantite,
          unite_id: unite_id,
          total: total,
          groupe: groupe, // <-- AJOUT ICI
        });
      }
    });

    var errors = [];
    if (!billingDate) errors.push("La date de facturation est obligatoire.");
    if (!dateEmission) errors.push("La date d’émission est obligatoire.");
    if (!clientId && projectMode === "NEW_PROJECT") errors.push("Sélectionnez le client du projet.");
    if (!newOfferReference && projectMode === "NEW_PROJECT") errors.push("Renseignez l’intitulé du projet.");
    if (!offreId && projectMode === "TS") errors.push("Sélectionnez le projet du devis TS.");
    if (!lignes.length) errors.push("Ajoutez au moins une ligne complète au devis.");
    if (errors.length) {
      result.html('<div class="alert alert-danger" role="alert"><strong>Enregistrement impossible.</strong><br>' + errors.join("<br>") + "</div>");
      result[0]?.scrollIntoView({ behavior: "smooth", block: "center" });
      return;
    }

    // Ajoutez les valeurs au FormData
    formData.append("numeroDevis", numeroDevis);
    formData.append("delaiLivraison", delaiLivraison);
    formData.append("dateEmission", dateEmission);
    formData.append("dateExpiration", dateExpiration);
    formData.append("termesConditions", termesConditions);
    formData.append("piedDePage", piedDePage);
    formData.append("totalHT", totalHT);
    formData.append("totalTTC", totalTTC);
    formData.append("billingDate", billingDate);
    formData.append("commercialDiscount", commercialDiscount);
    formData.append("tva", tva);
    formData.append("client_id", clientId);
    formData.append("offre_id", offreId);
    formData.append("devis_project_mode", projectMode);
    formData.append("new_offer_number", newOfferNumber);
    formData.append("new_offer_reference", newOfferReference);
    formData.append("tvaFacturable", tvaFacturable);
    formData.append("publierDevis", publierDevis);
    formData.append("correspondant", correspondant);

    if (logoFile) {
      formData.append("logo", logoFile);
    }

    // Ajoute les lignes de devis sous forme de JSON
    formData.append("lignes", JSON.stringify(lignes));

    // Effectuez la requête AJAX
    saveButton.prop("disabled", true).html('<span class="spinner-border spinner-border-sm me-2"></span>Enregistrement…');
    $.ajax({
      type: "POST",
      url: "request/generate_devis.php",
      data: formData,
      dataType: "json",
      cache: false,
      contentType: false,
      processData: false,
      success: function (response) {
        var devisId = parseInt(response.devis_id || 0, 10);
        if (!response || response.ok !== true || response.verified !== true || devisId <= 0) {
          result.html('<div class="alert alert-danger" role="alert"><strong>Enregistrement non confirmé.</strong><br>Le serveur n’a pas pu confirmer la présence du devis et de ses lignes. Réessayez sans actualiser le formulaire.</div>');
          saveButton.prop("disabled", false).html('<i class="fas fa-save"></i> Enregistrer le devis');
          return;
        }
        var popup = $(
          '<div role="status" aria-live="polite" style="position:fixed;inset:0;z-index:100000;display:flex;align-items:center;justify-content:center;padding:24px;background:rgba(15,23,42,.48);backdrop-filter:blur(5px)">' +
            '<div style="width:min(460px,100%);border:1px solid #bbf7d0;border-radius:24px;background:#fff;padding:30px;text-align:center;box-shadow:0 28px 80px rgba(15,23,42,.28)">' +
              '<div style="display:inline-flex;width:58px;height:58px;align-items:center;justify-content:center;border-radius:50%;background:#dcfce7;color:#15803d;font-size:28px"><i class="fas fa-check"></i></div>' +
              '<h2 style="margin:18px 0 8px;color:#172348;font-size:24px;font-weight:900">Devis ajouté avec succès</h2>' +
              '<p style="margin:0;color:#64748b">Le devis <strong>' + $("<div>").text(response.numero_devis || ("#" + devisId)).html() + '</strong> et ses <strong>' + Number(response.line_count || 0) + ' ligne(s)</strong> ont été vérifiés en base.</p>' +
              (response.project_number ? '<p style="margin:10px 0 0;color:#172348">Projet attribué : <strong>' + $("<div>").text(response.project_number).html() + '</strong></p>' : '') +
              '<p style="margin:10px 0 0;color:#64748b">Redirection vers la liste dans <strong data-quote-countdown>5</strong> secondes…</p>' +
            '</div>' +
          '</div>'
        );
        $("body").append(popup);
        $("#exportPdfBtn").show();
        $("#saveBtn").hide();
        var seconds = 5;
        var timer = window.setInterval(function () {
          seconds -= 1;
          popup.find("[data-quote-countdown]").text(Math.max(0, seconds));
          if (seconds <= 0) {
            window.clearInterval(timer);
            window.location.href = "devis/liste.php?focus_devis=" + encodeURIComponent(devisId) + "#devis-" + encodeURIComponent(devisId);
          }
        }, 1000);
      },
      error: function (xhr, status, error) {
        var response = xhr.responseJSON || {};
        var message = response.message || xhr.responseText || "Le devis n’a pas pu être enregistré.";
        result.html('<div class="alert alert-danger" role="alert"><strong>Enregistrement impossible.</strong><br>' + $("<div>").text(message).html() + "</div>");
        result[0]?.scrollIntoView({ behavior: "smooth", block: "center" });
        saveButton.prop("disabled", false).html('<i class="fas fa-save"></i> Enregistrer le devis');
      },
    });
  });

  function toggleProjectMode() {
    var mode = $('input[name="devis_project_mode"]:checked').val() || "NEW_PROJECT";
    $("#newProjectFields").toggle(mode === "NEW_PROJECT");
    $("#offreRequiredHint").toggle(mode === "TS");
  }
  $('input[name="devis_project_mode"]').on("change", toggleProjectMode);
  toggleProjectMode();

  $("#exportPdfBtn").on("click", function () {
    $(location).attr("href", "request/export_pdf.php");
  });
});

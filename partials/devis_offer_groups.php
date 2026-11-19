<?php foreach(($devisGroups??[])as$group):?>
<section class="offer-project-group mb-5" data-offer-id="<?=(int)$group['offre_id']?>">
 <header class="offer-project-header d-flex flex-wrap justify-content-between align-items-center gap-3">
  <div><div class="offer-project-kicker">PROJET</div><h2><?=htmlspecialchars((string)($group['offre_number']?:$group['offre_label']))?></h2><p><?=htmlspecialchars((string)$group['offre_label'])?></p></div>
  <span class="badge rounded-pill bg-light text-dark"><?=count($group['devis'])?> devis</span>
 </header>
 <div class="card-grid mt-3"><?php foreach($group['devis']as$de):?><?php include __DIR__.'/devis_card.php';?><?php endforeach?></div>
</section>
<?php endforeach?>

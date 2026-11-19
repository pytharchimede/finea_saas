<?php
require_once __DIR__.'/../repositories/FournisseurRepository.php';

/** Compatibility entry point: every supplier write uses the same compliance checks. */
class FournisseurMaster extends FournisseurRepository {}

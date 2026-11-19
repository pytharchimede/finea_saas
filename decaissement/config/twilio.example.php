<?php
// Exemple de configuration locale Twilio (copier en twilio.local.php et adapter)
// Ne validez pas vos secrets en dépôt.

if (!defined('TWILIO_SID')) define('TWILIO_SID', 'ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx');
if (!defined('TWILIO_TOKEN')) define('TWILIO_TOKEN', 'your_auth_token_here');
// Format requis: whatsapp:+2250712345678
if (!defined('TWILIO_WHATSAPP_FROM')) define('TWILIO_WHATSAPP_FROM', 'whatsapp:+2250712345678');

// Numéro du DG (sans indicatif, ex: 0544577666) – l’envoi normalise en +225...
if (!defined('DG_PHONE')) define('DG_PHONE', '0544577666');

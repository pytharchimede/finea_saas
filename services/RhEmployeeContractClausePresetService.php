<?php

final class RhEmployeeContractClausePresetService
{
    public static function forType(string $type): array
    {
        $type = strtoupper(trim($type));

        $common = [
            ['title'=>'Article 1 — Textes régissant le contrat','body'=>'Le présent contrat est régi par les dispositions légales et réglementaires applicables en Côte d’Ivoire, notamment le Code du travail, la Convention Collective Interprofessionnelle et leurs textes d’application ou de modification.'],
            ['title'=>'Article 3 — Congés payés','body'=>'Le salarié bénéficie des congés payés selon les droits acquis, les conditions d’ouverture, l’ordre et les dates de départ prévus par la législation, la convention collective applicable et les nécessités du service.'],
            ['title'=>'Article 4 — Déclaration et protection sociale','body'=>'L’employeur effectue les déclarations sociales obligatoires du salarié, notamment auprès de la CNPS, conformément à la réglementation en vigueur et aux informations administratives communiquées par le salarié.'],
            ['title'=>'Article 5 — Rémunération mensuelle','body'=>'Le salarié perçoit la rémunération définie dans la fiche contractuelle. Le versement est effectué selon l’échéance habituelle de l’entreprise, après application des cotisations, retenues et éléments variables validés.'],
            ['title'=>'Article 6 — Frais professionnels','body'=>'Les frais et débours professionnels autorisés, justifiés et engagés dans l’exercice des fonctions sont remboursés conformément aux procédures, notes de service et plafonds applicables dans l’entreprise.'],
            ['title'=>'Article 7 — Disponibilité et obligations professionnelles','body'=>'Le salarié exerce ses fonctions avec diligence, loyauté et conscience professionnelle. Il respecte les instructions de l’employeur, le règlement intérieur, les horaires, les règles de présence, de pointage et les consignes applicables à son poste.'],
            ['title'=>'Article 8 — Non-concurrence et conflits d’intérêts','body'=>'Le salarié s’abstient de toute activité professionnelle susceptible de nuire à la bonne exécution de ses fonctions ou de créer un conflit d’intérêts avec l’entreprise, dans les limites autorisées par le droit applicable.'],
            ['title'=>'Article 9 — Confidentialité et propriété des informations','body'=>'Le salarié préserve la confidentialité des informations, documents, fichiers et données auxquels il accède dans le cadre de ses fonctions. Les documents et productions réalisés ou remis pour les besoins de l’entreprise demeurent sa propriété, sous réserve des règles légales applicables.'],
            ['title'=>'Article 10 — Règlement des différends','body'=>'Les parties s’engagent à exécuter le contrat de bonne foi et recherchent d’abord une solution amiable à tout différend relatif à son interprétation ou à son exécution. À défaut, les autorités et juridictions compétentes peuvent être saisies conformément au droit applicable.'],
            ['title'=>'Article 11 — Modification du contrat','body'=>'Toute modification substantielle du présent contrat intervient d’un commun accord et fait l’objet d’un écrit ou d’un avenant signé par les parties.'],
            ['title'=>'Article 12 — Restitution des biens de l’entreprise','body'=>'À la fin de la relation de travail ou sur demande justifiée de l’employeur, le salarié restitue les documents, équipements, matériels, accès et autres biens appartenant à l’entreprise qui lui ont été confiés.'],
            ['title'=>'Article 13 — Prise d’effet et exemplaires','body'=>'Le contrat prend effet à la date indiquée dans sa fiche. Il est établi et conservé dans les conditions permettant à chaque partie de disposer d’un exemplaire opposable, y compris sous forme électronique lorsque le circuit de signature FINEA est utilisé.'],
        ];

        $specific = match ($type) {
            'CDD' => [
                ['title'=>'Article 2 — Engagement et durée du CDD','body'=>'Le salarié est engagé pour la durée déterminée indiquée dans la fiche contractuelle, aux fonctions, lieu d’affectation et horaires qui y sont précisés. Il peut être affecté sur un autre site ou chantier selon les nécessités de service, dans le respect du cadre légal applicable.'],
                ['title'=>'Article 14 — Rupture et fin du CDD','body'=>'Le contrat à durée déterminée prend fin au terme convenu. Toute rupture anticipée, indemnité de fin de contrat ou transformation éventuelle de la relation de travail est régie par les dispositions légales et conventionnelles applicables.'],
            ],
            'CDI' => [
                ['title'=>'Article 2 — Engagement et durée du CDI','body'=>'Le salarié est engagé sans terme déterminé à compter de la date indiquée dans la fiche contractuelle, aux fonctions et conditions qui y sont définies.'],
                ['title'=>'Article 14 — Rupture du CDI','body'=>'La rupture du contrat à durée indéterminée intervient selon les procédures, délais de préavis, motifs et droits prévus par la législation et la convention collective applicables.'],
            ],
            'STAGE' => [
                ['title'=>'Article 2 — Objet et durée du stage','body'=>'Le stage est conclu pour la période indiquée dans la fiche contractuelle. Il a pour objet l’acquisition ou le perfectionnement de compétences professionnelles sous la supervision du responsable désigné.'],
                ['title'=>'Article 14 — Fin du stage','body'=>'Le stage prend fin à la date convenue ou dans les conditions prévues par la convention de stage et les textes applicables.'],
            ],
            'VACATAIRE' => [
                ['title'=>'Article 2 — Objet et durée de la vacation','body'=>'Les vacations sont réalisées selon les missions, périodes, horaires et modalités de rémunération prévues dans la fiche contractuelle et validées par l’entreprise.'],
                ['title'=>'Article 14 — Fin de la vacation','body'=>'La vacation prend fin selon les modalités convenues et les règles applicables à la relation contractuelle.'],
            ],
            default => [
                ['title'=>'Article 2 — Engagement et durée','body'=>'Le salarié est engagé aux fonctions, dates et conditions indiquées dans la fiche contractuelle, conformément au droit du travail applicable.'],
                ['title'=>'Article 14 — Fin de la relation de travail','body'=>'La fin de la relation de travail est régie par les règles légales, conventionnelles et contractuelles applicables.'],
            ],
        };

        return array_merge(array_slice($common, 0, 1), array_slice($specific, 0, 1), array_slice($common, 1), array_slice($specific, 1));
    }
}

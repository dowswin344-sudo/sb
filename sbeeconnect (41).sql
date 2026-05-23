-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Hôte : 127.0.0.1:3306
-- Généré le : ven. 22 mai 2026 à 12:52
-- Version du serveur : 8.4.7
-- Version de PHP : 8.3.28

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de données : `sbeeconnect`
--

-- --------------------------------------------------------

--
-- Structure de la table `alertes`
--

DROP TABLE IF EXISTS `alertes`;
CREATE TABLE IF NOT EXISTS `alertes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `reclamation_id` int DEFAULT NULL,
  `type_alerte` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'info',
  `priorite` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'moyenne',
  `message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `url_action` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `lue` tinyint(1) NOT NULL DEFAULT '0',
  `expire_le` datetime DEFAULT NULL,
  `destinataire_id` int NOT NULL,
  `date_creation` datetime DEFAULT CURRENT_TIMESTAMP,
  `niveau_criticite` int NOT NULL DEFAULT '1',
  `traitee` tinyint(1) NOT NULL DEFAULT '0',
  `date_traitement` datetime DEFAULT NULL,
  `traitee_par_id` int DEFAULT NULL,
  `temps_traitement_minutes` int DEFAULT NULL,
  `evaluation_id` int DEFAULT NULL COMMENT 'Lien direct vers evaluations.id',
  `signalement_id` int DEFAULT NULL COMMENT 'Lien direct vers signalements.id',
  PRIMARY KEY (`id`),
  KEY `reclamation_id` (`reclamation_id`),
  KEY `destinataire_id` (`destinataire_id`),
  KEY `idx_alertes_priorite_lue` (`destinataire_id`,`lue`,`priorite`,`date_creation`),
  KEY `idx_alertes_traitement` (`traitee`,`niveau_criticite`,`date_creation`),
  KEY `idx_alertes_evaluation` (`evaluation_id`),
  KEY `idx_alertes_signalement_direct` (`signalement_id`),
  KEY `idx_rapports_alertes_reclamation` (`reclamation_id`,`date_creation`,`lue`),
  KEY `idx_profile_alertes_dest` (`destinataire_id`,`lue`,`date_creation`),
  KEY `idx_alertes_signalement_abonne` (`signalement_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `coupures_programmees`
--

DROP TABLE IF EXISTS `coupures_programmees`;
CREATE TABLE IF NOT EXISTS `coupures_programmees` (
  `id` int NOT NULL AUTO_INCREMENT,
  `zone_id` int DEFAULT NULL,
  `titre` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `cause` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `date_debut` datetime NOT NULL,
  `date_fin` datetime NOT NULL,
  `statut` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'prevue',
  `impact_estime` int DEFAULT NULL,
  `responsable_id` int DEFAULT NULL,
  `publication_en_ligne` tinyint(1) NOT NULL DEFAULT '1',
  `date_publication` datetime DEFAULT NULL,
  `preavis_envoye` tinyint(1) NOT NULL DEFAULT '0',
  `cree_le` datetime DEFAULT CURRENT_TIMESTAMP,
  `modifie_le` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `niveau_impact` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'faible, moyen, eleve, critique',
  `nombre_abonnes_impactes` int DEFAULT NULL,
  `canaux_preavis` json DEFAULT NULL COMMENT 'sms, email, web, whatsapp',
  `notifications_envoyees` int NOT NULL DEFAULT '0',
  `taux_couverture_notification` decimal(5,2) DEFAULT NULL,
  `motif_report` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `date_fin_reelle` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `zone_id` (`zone_id`),
  KEY `idx_coupures_publication_dates` (`publication_en_ligne`,`date_debut`,`date_fin`),
  KEY `idx_coupures_responsable` (`responsable_id`,`statut`),
  KEY `idx_coupures_impact` (`niveau_impact`,`date_debut`),
  KEY `idx_coupures_zone_dates` (`zone_id`,`date_debut`,`date_fin`),
  KEY `idx_rapports_coupures_zone_date` (`zone_id`,`date_debut`,`statut`),
  KEY `idx_profile_coupures_zone` (`zone_id`,`date_debut`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `elements_masques_agent`
--

DROP TABLE IF EXISTS `elements_masques_agent`;
CREATE TABLE IF NOT EXISTS `elements_masques_agent` (
  `id` int NOT NULL AUTO_INCREMENT,
  `agent_id` int NOT NULL,
  `element_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `element_id` int NOT NULL,
  `motif` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `date_masquage` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_agent_element_masque` (`agent_id`,`element_type`,`element_id`),
  KEY `idx_agent_masques_type` (`element_type`,`element_id`),
  KEY `idx_agent_masques_date` (`agent_id`,`date_masquage`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `evaluations`
--

DROP TABLE IF EXISTS `evaluations`;
CREATE TABLE IF NOT EXISTS `evaluations` (
  `id` int NOT NULL AUTO_INCREMENT,
  `reclamation_id` int NOT NULL,
  `signalement_id` int DEFAULT NULL,
  `note` int DEFAULT NULL,
  `canal_evaluation` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'web',
  `commentaire` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `motif_insatisfaction` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `date_evaluation` datetime DEFAULT CURRENT_TIMESTAMP,
  `repondu` tinyint(1) NOT NULL DEFAULT '0',
  `reponse_admin` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `date_reponse_admin` datetime DEFAULT NULL,
  `admin_id` int DEFAULT NULL,
  `publiee` tinyint(1) NOT NULL DEFAULT '0',
  `visible_anonymement` tinyint(1) NOT NULL DEFAULT '1',
  `note_rapidite` int DEFAULT NULL COMMENT 'Note rapidité 1 à 5',
  `note_qualite` int DEFAULT NULL COMMENT 'Note qualité 1 à 5',
  `note_communication` int DEFAULT NULL COMMENT 'Note communication 1 à 5',
  `recommande_service` tinyint(1) DEFAULT NULL,
  `source_evaluation` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'sms, web, appel, whatsapp',
  `date_moderation` datetime DEFAULT NULL COMMENT 'Date de modération ou de publication de l’évaluation',
  `moderateur_id` int DEFAULT NULL COMMENT 'Utilisateur admin/modérateur ayant traité l’évaluation',
  PRIMARY KEY (`id`),
  UNIQUE KEY `reclamation_id` (`reclamation_id`),
  KEY `idx_evaluations_signalement` (`signalement_id`),
  KEY `idx_evaluations_note_date` (`note`,`date_evaluation`),
  KEY `fk_evaluations_admin` (`admin_id`),
  KEY `idx_evaluations_detail` (`note_rapidite`,`note_qualite`,`note_communication`),
  KEY `idx_evaluations_moderation` (`publiee`,`repondu`,`date_moderation`),
  KEY `idx_evaluations_moderateur` (`moderateur_id`),
  KEY `idx_rapports_eval_reclamation` (`reclamation_id`,`note`,`date_evaluation`),
  KEY `idx_rapports_eval_signalement` (`signalement_id`,`note`,`date_evaluation`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déclencheurs `evaluations`
--
DROP TRIGGER IF EXISTS `trg_evaluations_bi`;
DELIMITER $$
CREATE TRIGGER `trg_evaluations_bi` BEFORE INSERT ON `evaluations` FOR EACH ROW BEGIN
  IF NEW.`signalement_id` IS NULL THEN
    SET NEW.`signalement_id` = NEW.`reclamation_id`;
  END IF;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trg_evaluations_bu`;
DELIMITER $$
CREATE TRIGGER `trg_evaluations_bu` BEFORE UPDATE ON `evaluations` FOR EACH ROW BEGIN
  IF NEW.`signalement_id` IS NULL THEN
    SET NEW.`signalement_id` = NEW.`reclamation_id`;
  END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Structure de la table `historique_abonne_masques`
--

DROP TABLE IF EXISTS `historique_abonne_masques`;
CREATE TABLE IF NOT EXISTS `historique_abonne_masques` (
  `id` int NOT NULL AUTO_INCREMENT,
  `abonne_id` int NOT NULL,
  `event_type` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `event_id` int NOT NULL,
  `date_masquage` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_abonne_event` (`abonne_id`,`event_type`,`event_id`),
  KEY `idx_abonne_date` (`abonne_id`,`date_masquage`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `interventions`
--

DROP TABLE IF EXISTS `interventions`;
CREATE TABLE IF NOT EXISTS `interventions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `signalement_id` int NOT NULL,
  `agent_id` int NOT NULL,
  `date_debut` datetime NOT NULL,
  `date_arrivee_site` datetime DEFAULT NULL,
  `date_fin` datetime DEFAULT NULL,
  `duree_intervention_minutes` int DEFAULT NULL,
  `statut_intervention` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'en_route',
  `resultat_intervention` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `commentaire_terrain` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `pieces_utilisees` json DEFAULT NULL,
  `materiel_manquant` tinyint(1) NOT NULL DEFAULT '0',
  `coordonnees_gps` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fichiers_media` text COLLATE utf8mb4_unicode_ci,
  `diagnostic` text COLLATE utf8mb4_unicode_ci COMMENT 'Diagnostic posé sur le terrain',
  `action_effectuee` text COLLATE utf8mb4_unicode_ci COMMENT 'Travaux ou actions réalisés',
  `date_depart_site` datetime DEFAULT NULL,
  `qualite_retablissement` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'definitif, temporaire, partiel',
  `verification_apres_intervention` tinyint(1) NOT NULL DEFAULT '0',
  `signature_abonne` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Image ou preuve de validation client',
  `incident_securite` tinyint(1) NOT NULL DEFAULT '0',
  `distance_parcourue_km` decimal(6,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `signalement_id` (`signalement_id`),
  KEY `agent_id` (`agent_id`),
  KEY `idx_interventions_dates` (`date_debut`,`date_fin`),
  KEY `idx_interventions_agent_statut` (`agent_id`,`statut_intervention`,`date_debut`),
  KEY `idx_interventions_resultat` (`resultat_intervention`,`qualite_retablissement`),
  KEY `idx_rapports_interventions_signalement` (`signalement_id`,`date_debut`),
  KEY `idx_profile_interventions_agent` (`agent_id`,`date_debut`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déclencheurs `interventions`
--
DROP TRIGGER IF EXISTS `trg_interventions_ai`;
DELIMITER $$
CREATE TRIGGER `trg_interventions_ai` AFTER INSERT ON `interventions` FOR EACH ROW BEGIN
  UPDATE `signalements`
  SET `date_premiere_intervention` = COALESCE(`date_premiere_intervention`, NEW.`date_debut`)
  WHERE `id` = NEW.`signalement_id`;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trg_interventions_au`;
DELIMITER $$
CREATE TRIGGER `trg_interventions_au` AFTER UPDATE ON `interventions` FOR EACH ROW BEGIN
  IF NEW.`statut_intervention` = 'terminee' AND NEW.`date_fin` IS NOT NULL THEN
    UPDATE `signalements`
    SET `date_resolution` = COALESCE(`date_resolution`, NEW.`date_fin`),
        `temps_total_resolution` = TIMESTAMPDIFF(MINUTE, `date_creation`, COALESCE(`date_resolution`, NEW.`date_fin`))
    WHERE `id` = NEW.`signalement_id`;
  END IF;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trg_interventions_bu`;
DELIMITER $$
CREATE TRIGGER `trg_interventions_bu` BEFORE UPDATE ON `interventions` FOR EACH ROW BEGIN
  IF NEW.`date_fin` IS NOT NULL THEN
    SET NEW.`duree_intervention_minutes` = TIMESTAMPDIFF(MINUTE, NEW.`date_debut`, NEW.`date_fin`);
  END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Structure de la table `messages_abonnes`
--

DROP TABLE IF EXISTS `messages_abonnes`;
CREATE TABLE IF NOT EXISTS `messages_abonnes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `abonne_id` int NOT NULL,
  `signalement_id` int DEFAULT NULL,
  `message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `statut` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ouvert',
  `reponse` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `piece_jointe` text COLLATE utf8mb4_unicode_ci,
  `date_creation` datetime DEFAULT CURRENT_TIMESTAMP,
  `date_reponse` datetime DEFAULT NULL,
  `canal_entree` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT 'web' COMMENT 'web, mobile, whatsapp, appel',
  `priorite` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'moyenne',
  `assigne_a_id` int DEFAULT NULL,
  `motif_cloture` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `temps_reponse_minutes` int DEFAULT NULL,
  `notification_id` int DEFAULT NULL COMMENT 'Dernière notification liée au message abonné',
  `sujet` varchar(180) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Sujet court du message abonné',
  `lu` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Message lu par l’administration',
  `repondu` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Message répondu par l’administration',
  PRIMARY KEY (`id`),
  KEY `idx_abonne` (`abonne_id`),
  KEY `idx_signalement` (`signalement_id`),
  KEY `idx_messages_abonnes_statut` (`statut`,`date_creation`),
  KEY `idx_messages_abonnes_priorite` (`priorite`,`statut`,`date_creation`),
  KEY `idx_messages_abonnes_assigne` (`assigne_a_id`,`statut`),
  KEY `idx_messages_abonnes_notification` (`notification_id`),
  KEY `idx_rapports_messages_signalement` (`signalement_id`,`date_creation`,`statut`),
  KEY `idx_profile_messages_abonne` (`abonne_id`,`date_creation`),
  KEY `idx_messages_abonnes_abonne_date` (`abonne_id`,`date_creation`),
  KEY `idx_messages_abonnes_signalement` (`signalement_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `messages_contact`
--

DROP TABLE IF EXISTS `messages_contact`;
CREATE TABLE IF NOT EXISTS `messages_contact` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nom` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `sujet` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `categorie` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'general',
  `priorite` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'moyenne',
  `assigne_a_id` int DEFAULT NULL,
  `message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `statut` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'en_attente',
  `reponse` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `date_reponse` datetime DEFAULT NULL,
  `lu` tinyint(1) NOT NULL DEFAULT '0',
  `date_creation` datetime DEFAULT CURRENT_TIMESTAMP,
  `repondu` tinyint(1) NOT NULL DEFAULT '0',
  `date_modification` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `canal_entree` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT 'web',
  `date_premiere_lecture` datetime DEFAULT NULL,
  `motif_cloture` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `temps_reponse_minutes` int DEFAULT NULL,
  `satisfaction_client` int DEFAULT NULL COMMENT 'Note 1 à 5 après réponse',
  `ip_source` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `signalement_id` int DEFAULT NULL COMMENT 'Dossier/signalement créé depuis ce message',
  `notification_id` int DEFAULT NULL COMMENT 'Dernière notification liée au message contact',
  `note_interne` text COLLATE utf8mb4_unicode_ci COMMENT 'Note interne de traitement',
  PRIMARY KEY (`id`),
  KEY `idx_lu` (`lu`),
  KEY `idx_date_creation` (`date_creation`),
  KEY `idx_messages_contact_triage` (`statut`,`priorite`,`lu`),
  KEY `idx_messages_contact_assigne` (`assigne_a_id`,`statut`),
  KEY `idx_messages_contact_canal` (`canal_entree`,`date_creation`),
  KEY `idx_messages_contact_satisfaction` (`satisfaction_client`),
  KEY `idx_messages_contact_signalement` (`signalement_id`),
  KEY `idx_messages_contact_notification` (`notification_id`),
  KEY `idx_rapports_messages_contact` (`date_creation`,`lu`,`repondu`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `notifications`
--

DROP TABLE IF EXISTS `notifications`;
CREATE TABLE IF NOT EXISTS `notifications` (
  `id` int NOT NULL AUTO_INCREMENT,
  `coupure_id` int DEFAULT NULL,
  `reclamation_id` int DEFAULT NULL,
  `destinataire_utilisateur_id` int DEFAULT NULL,
  `destinataire_telephone` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `destinataire_email` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `type_notification` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'sms',
  `statut_envoi` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'envoye',
  `tentatives` int NOT NULL DEFAULT '1',
  `date_derniere_tentative` datetime DEFAULT NULL,
  `erreur_envoi` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reference_operateur` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `date_envoi` datetime DEFAULT CURRENT_TIMESTAMP,
  `canal` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT 'sms' COMMENT 'sms, email, whatsapp, push',
  `statut_livraison` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'delivre, echec, en_attente',
  `date_livraison` datetime DEFAULT NULL,
  `cout_estime` decimal(8,2) DEFAULT NULL,
  `fournisseur` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Nom fournisseur SMS/email',
  `payload_reponse` json DEFAULT NULL COMMENT 'Réponse brute fournisseur',
  `message_contact_id` int DEFAULT NULL COMMENT 'Message contact lié',
  `message_abonne_id` int DEFAULT NULL COMMENT 'Message abonné lié',
  `portee_notification` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT 'individuel' COMMENT 'individuel, zone, zones, systeme',
  `cible_notification` text COLLATE utf8mb4_unicode_ci COMMENT 'Cible JSON ou description',
  `evaluation_id` int DEFAULT NULL COMMENT 'Lien direct vers evaluations.id',
  `signalement_id` int DEFAULT NULL COMMENT 'Lien direct vers signalements.id',
  `destinataire_id` int DEFAULT NULL COMMENT 'Utilisateur destinataire direct si notification interne',
  PRIMARY KEY (`id`),
  KEY `reclamation_id` (`reclamation_id`),
  KEY `idx_notifications_statut_retry` (`statut_envoi`,`date_derniere_tentative`),
  KEY `idx_notifications_livraison` (`statut_livraison`,`date_livraison`),
  KEY `idx_notifications_canal` (`canal`,`statut_envoi`),
  KEY `idx_notifications_coupure` (`coupure_id`,`canal`,`statut_envoi`),
  KEY `idx_notifications_destinataire_user` (`destinataire_utilisateur_id`,`canal`,`date_envoi`),
  KEY `idx_notifications_message_contact` (`message_contact_id`),
  KEY `idx_notifications_message_abonne` (`message_abonne_id`),
  KEY `idx_notifications_dest_user` (`destinataire_utilisateur_id`),
  KEY `idx_notifications_evaluation` (`evaluation_id`),
  KEY `idx_notifications_signalement_direct` (`signalement_id`),
  KEY `idx_notifications_destinataire_utilisateur_eval` (`destinataire_utilisateur_id`),
  KEY `idx_rapports_notifications_reclamation` (`reclamation_id`,`date_envoi`,`statut_envoi`),
  KEY `idx_profile_notifications_email` (`destinataire_email`,`date_envoi`),
  KEY `idx_profile_notifications_phone` (`destinataire_telephone`,`date_envoi`),
  KEY `idx_notifications_signalement_abonne` (`signalement_id`),
  KEY `idx_notifications_dest_user_abonne` (`destinataire_utilisateur_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `signalements`
--

DROP TABLE IF EXISTS `signalements`;
CREATE TABLE IF NOT EXISTS `signalements` (
  `id` int NOT NULL AUTO_INCREMENT,
  `abonne_id` int DEFAULT NULL,
  `telephone_contact` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nom_contact` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `numero_compteur_saisi` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `numero_reference` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `type_panne` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `adresse_texte` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `zone_id` int DEFAULT NULL,
  `statut` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'recue',
  `priorite` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'moyenne',
  `urgence` tinyint(1) NOT NULL DEFAULT '0',
  `agent_assignee_id` int DEFAULT NULL,
  `date_assignation` datetime DEFAULT NULL,
  `date_premiere_intervention` datetime DEFAULT NULL,
  `sla_echeance` datetime DEFAULT NULL,
  `source` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'web',
  `date_creation` datetime DEFAULT CURRENT_TIMESTAMP,
  `date_mise_a_jour` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `date_resolution` datetime DEFAULT NULL,
  `temps_total_resolution` int DEFAULT NULL,
  `commentaires_internes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `publication_en_ligne` tinyint(1) NOT NULL DEFAULT '0',
  `fichier` text COLLATE utf8mb4_unicode_ci,
  `supprime` tinyint(1) NOT NULL DEFAULT '0',
  `canal_detail` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'web, mobile, whatsapp, appel, guichet',
  `niveau_criticite` int NOT NULL DEFAULT '1' COMMENT '1=normal, 2=important, 3=critique',
  `cause_probable` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Cause estimée avant intervention',
  `est_recurrent` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1 si panne récurrente',
  `temps_reaction_minutes` int DEFAULT NULL COMMENT 'Temps entre création et assignation',
  `sla_respecte` tinyint(1) DEFAULT NULL COMMENT '1 si résolu avant échéance SLA',
  `escalade` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1 si escaladé vers superviseur',
  `raison_escalade` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `date_cloture` datetime DEFAULT NULL,
  `cree_par_id` int DEFAULT NULL COMMENT 'Utilisateur ayant créé/enregistré le signalement',
  `modifie_par_id` int DEFAULT NULL COMMENT 'Dernier utilisateur ayant modifié le signalement',
  PRIMARY KEY (`id`),
  UNIQUE KEY `numero_reference` (`numero_reference`),
  KEY `abonne_id` (`abonne_id`),
  KEY `zone_id` (`zone_id`),
  KEY `agent_assignee_id` (`agent_assignee_id`),
  KEY `idx_signalements_sla` (`sla_echeance`,`statut`,`priorite`),
  KEY `idx_signalements_agent_statut` (`agent_assignee_id`,`statut`),
  KEY `idx_signalements_resolution` (`date_creation`,`date_resolution`),
  KEY `idx_signalements_criticite` (`niveau_criticite`,`statut`,`date_creation`),
  KEY `idx_signalements_recurrent` (`est_recurrent`,`zone_id`,`type_panne`),
  KEY `idx_signalements_sla_respecte` (`sla_respecte`,`date_resolution`),
  KEY `idx_rapports_ref_date` (`numero_reference`,`date_creation`),
  KEY `idx_rapports_zone_ref` (`zone_id`,`numero_reference`),
  KEY `idx_rapports_sla_statut` (`sla_echeance`,`statut`),
  KEY `idx_profile_abonne_date` (`abonne_id`,`date_creation`),
  KEY `idx_profile_agent_date` (`agent_assignee_id`,`date_creation`),
  KEY `idx_profile_zone_date` (`zone_id`,`date_creation`),
  KEY `idx_signalements_abonne_statut` (`abonne_id`,`statut`),
  KEY `idx_signalements_abonne_date` (`abonne_id`,`date_creation`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déclencheurs `signalements`
--
DROP TRIGGER IF EXISTS `trg_signalements_bi`;
DELIMITER $$
CREATE TRIGGER `trg_signalements_bi` BEFORE INSERT ON `signalements` FOR EACH ROW BEGIN
  IF NEW.date_creation IS NULL THEN
    SET NEW.date_creation = NOW();
  END IF;

  IF NEW.priorite IS NULL OR NEW.priorite = '' THEN
    SET NEW.priorite = 'basse';
  END IF;

  SET NEW.sla_echeance = DATE_ADD(
    NEW.date_creation,
    INTERVAL CASE
      WHEN NEW.priorite = 'haute' THEN 12
      WHEN NEW.priorite = 'moyenne' THEN 24
      ELSE 36
    END HOUR
  );

  IF NEW.agent_assignee_id IS NOT NULL AND NEW.date_assignation IS NULL THEN
    SET NEW.date_assignation = NOW();
  END IF;

  IF NEW.statut IN ('resolu', 'terminee', 'ferme') AND NEW.date_resolution IS NULL THEN
    SET NEW.date_resolution = NOW();
  END IF;

  IF NEW.date_resolution IS NOT NULL AND NEW.temps_total_resolution IS NULL THEN
    SET NEW.temps_total_resolution = TIMESTAMPDIFF(MINUTE, COALESCE(NEW.date_creation, NOW()), NEW.date_resolution);
  END IF;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trg_signalements_bu`;
DELIMITER $$
CREATE TRIGGER `trg_signalements_bu` BEFORE UPDATE ON `signalements` FOR EACH ROW BEGIN
  IF NEW.date_creation IS NULL THEN
    SET NEW.date_creation = COALESCE(OLD.date_creation, NOW());
  END IF;

  IF NEW.priorite IS NULL OR NEW.priorite = '' THEN
    SET NEW.priorite = COALESCE(OLD.priorite, 'basse');
  END IF;

  -- On ne redémarre pas le compteur : on recalcule depuis la date_creation initiale.
  SET NEW.sla_echeance = DATE_ADD(
    NEW.date_creation,
    INTERVAL CASE
      WHEN NEW.priorite = 'haute' THEN 12
      WHEN NEW.priorite = 'moyenne' THEN 24
      ELSE 36
    END HOUR
  );

  IF NEW.agent_assignee_id IS NOT NULL
     AND (OLD.agent_assignee_id IS NULL OR OLD.agent_assignee_id <> NEW.agent_assignee_id)
     AND NEW.date_assignation IS NULL THEN
    SET NEW.date_assignation = NOW();
  END IF;

  IF NEW.statut IN ('resolu', 'terminee', 'ferme')
     AND OLD.statut NOT IN ('resolu', 'terminee', 'ferme')
     AND NEW.date_resolution IS NULL THEN
    SET NEW.date_resolution = NOW();
  END IF;

  IF NEW.date_resolution IS NOT NULL THEN
    SET NEW.temps_total_resolution = TIMESTAMPDIFF(MINUTE, NEW.date_creation, NEW.date_resolution);
  END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Structure de la table `utilisateurs`
--

DROP TABLE IF EXISTS `utilisateurs`;
CREATE TABLE IF NOT EXISTS `utilisateurs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nom` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `prenom` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telephone` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `mot_de_passe` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `numero_compteur` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `adresse` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `zone_id` int DEFAULT NULL,
  `matricule_agent` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `equipe` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `statut_disponibilite` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `derniere_connexion` datetime DEFAULT NULL,
  `photo` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `actif` tinyint(1) NOT NULL DEFAULT '1',
  `date_creation` datetime DEFAULT CURRENT_TIMESTAMP,
  `date_modification` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `email_verifie` tinyint(1) NOT NULL DEFAULT '0',
  `telephone_verifie` tinyint(1) NOT NULL DEFAULT '0',
  `derniere_activite` datetime DEFAULT NULL,
  `derniere_ip_connexion` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tentative_connexion` int NOT NULL DEFAULT '0',
  `blocage_jusqua` datetime DEFAULT NULL,
  `score_performance` decimal(5,2) DEFAULT NULL COMMENT 'Score agent calculé',
  `nombre_interventions_realisees` int NOT NULL DEFAULT '0',
  `notification_silence_jusqua` datetime DEFAULT NULL,
  `preferences_notifications` json DEFAULT NULL,
  `derniere_position_gps` varchar(180) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Dernière position GPS saisie ou validée par l’agent',
  PRIMARY KEY (`id`),
  UNIQUE KEY `telephone` (`telephone`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `numero_compteur` (`numero_compteur`),
  UNIQUE KEY `matricule_agent` (`matricule_agent`),
  KEY `zone_id` (`zone_id`),
  KEY `idx_utilisateurs_role_dispo` (`role`,`statut_disponibilite`,`actif`),
  KEY `idx_utilisateurs_activite` (`derniere_activite`),
  KEY `idx_utilisateurs_score` (`role`,`score_performance`)
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `utilisateurs`
--

INSERT INTO `utilisateurs` (`id`, `nom`, `prenom`, `email`, `telephone`, `mot_de_passe`, `role`, `numero_compteur`, `adresse`, `zone_id`, `matricule_agent`, `equipe`, `statut_disponibilite`, `derniere_connexion`, `photo`, `actif`, `date_creation`, `date_modification`, `email_verifie`, `telephone_verifie`, `derniere_activite`, `derniere_ip_connexion`, `tentative_connexion`, `blocage_jusqua`, `score_performance`, `nombre_interventions_realisees`, `notification_silence_jusqua`, `preferences_notifications`, `derniere_position_gps`) VALUES
(4, 'ADMINISTRATEUR', 'SYSTEME', 'admin@sbee.bj', '+22999000001', '$2y$10$tPXJvSLvdB3IONoH8Hyn0uhtT5igeRND/LuAkIlnR34uDQsNfUV0G', 'admin', NULL, 'Siège SBEE Cotonou', 2, NULL, NULL, NULL, '2026-05-22 10:59:21', 'uploads/avatars/avatar_4_20260521223432_5c81ba22.jpg', 1, '2026-04-29 16:43:50', '2026-05-22 11:00:14', 1, 1, '2026-05-22 11:00:14', '::1', 0, NULL, NULL, 2, '2026-04-25 22:38:00', '{\"sms\": true, \"push\": true, \"email\": true, \"whatsapp\": true, \"alertes_critiques\": true, \"canal_preferentiel\": \"email\", \"resume_hebdomadaire\": true}', NULL);

-- --------------------------------------------------------

--
-- Structure de la table `zones`
--

DROP TABLE IF EXISTS `zones`;
CREATE TABLE IF NOT EXISTS `zones` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nom` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `code_zone` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `parent_id` int DEFAULT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `latitude_centre` decimal(10,8) DEFAULT NULL,
  `longitude_centre` decimal(11,8) DEFAULT NULL,
  `temps_reponse_cible_minutes` int NOT NULL DEFAULT '120',
  `actif` tinyint(1) NOT NULL DEFAULT '1',
  `date_creation` datetime DEFAULT CURRENT_TIMESTAMP,
  `date_modification` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `responsable_zone_id` int DEFAULT NULL,
  `niveau_priorite` int NOT NULL DEFAULT '1' COMMENT '1 normal, 2 sensible, 3 critique',
  `nombre_signalements_mois` int NOT NULL DEFAULT '0',
  `temps_moyen_resolution_minutes` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_zones_code` (`code_zone`),
  KEY `idx_zones_parent_actif` (`parent_id`,`actif`),
  KEY `idx_zones_priorite` (`niveau_priorite`,`actif`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Contraintes pour les tables déchargées
--

--
-- Contraintes pour la table `alertes`
--
ALTER TABLE `alertes`
  ADD CONSTRAINT `fk_alertes_destinataire` FOREIGN KEY (`destinataire_id`) REFERENCES `utilisateurs` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_alertes_signalement` FOREIGN KEY (`reclamation_id`) REFERENCES `signalements` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `coupures_programmees`
--
ALTER TABLE `coupures_programmees`
  ADD CONSTRAINT `fk_coupures_responsable` FOREIGN KEY (`responsable_id`) REFERENCES `utilisateurs` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_coupures_zone` FOREIGN KEY (`zone_id`) REFERENCES `zones` (`id`) ON DELETE SET NULL;

--
-- Contraintes pour la table `evaluations`
--
ALTER TABLE `evaluations`
  ADD CONSTRAINT `fk_evaluations_admin` FOREIGN KEY (`admin_id`) REFERENCES `utilisateurs` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_evaluations_signalement` FOREIGN KEY (`reclamation_id`) REFERENCES `signalements` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `interventions`
--
ALTER TABLE `interventions`
  ADD CONSTRAINT `fk_interventions_agent` FOREIGN KEY (`agent_id`) REFERENCES `utilisateurs` (`id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_interventions_signalement` FOREIGN KEY (`signalement_id`) REFERENCES `signalements` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `messages_abonnes`
--
ALTER TABLE `messages_abonnes`
  ADD CONSTRAINT `fk_messages_abonnes_abonne` FOREIGN KEY (`abonne_id`) REFERENCES `utilisateurs` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_messages_abonnes_signalement` FOREIGN KEY (`signalement_id`) REFERENCES `signalements` (`id`) ON DELETE SET NULL;

--
-- Contraintes pour la table `messages_contact`
--
ALTER TABLE `messages_contact`
  ADD CONSTRAINT `fk_messages_contact_assigne` FOREIGN KEY (`assigne_a_id`) REFERENCES `utilisateurs` (`id`) ON DELETE SET NULL;

--
-- Contraintes pour la table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `fk_notifications_signalement` FOREIGN KEY (`reclamation_id`) REFERENCES `signalements` (`id`) ON DELETE SET NULL;

--
-- Contraintes pour la table `signalements`
--
ALTER TABLE `signalements`
  ADD CONSTRAINT `fk_signalements_abonne` FOREIGN KEY (`abonne_id`) REFERENCES `utilisateurs` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_signalements_agent` FOREIGN KEY (`agent_assignee_id`) REFERENCES `utilisateurs` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_signalements_zone` FOREIGN KEY (`zone_id`) REFERENCES `zones` (`id`) ON DELETE SET NULL;

--
-- Contraintes pour la table `utilisateurs`
--
ALTER TABLE `utilisateurs`
  ADD CONSTRAINT `fk_utilisateurs_zone` FOREIGN KEY (`zone_id`) REFERENCES `zones` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
